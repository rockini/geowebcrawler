<?php
declare(strict_types=1);

namespace GeoWebCrawler\Sparql;

use GeoWebCrawler\Config;
use GeoWebCrawler\Http\HttpClient;
use GeoWebCrawler\Storage\FileStore;
use PDO;

/**
 * Scarica in locale un gruppo di dati SPARQL, una pagina alla volta, in un file esterno al database:
 *  - csv: una riga per oggetto, con le colonne scelte (piu' valori nella stessa cella separati da " | ");
 *         se ci sono coordinate (lat/long) o geometrie WKT si crea anche un file GeoJSON;
 *  - rdf: tutte le triple degli oggetti (N-Triples o Turtle, come le fornisce il server);
 *  - file: per i dataset DCAT, i file indicati come indirizzi di scaricamento.
 *
 * Le pagine si chiedono "dopo l'ultimo oggetto ricevuto" (ordinando per IRI) invece che con OFFSET:
 * Virtuoso non permette di ordinare oltre un certo numero di righe (errore SR353) e con OFFSET alti diventa lento.
 */
final class Downloader
{
    private ?\Closure $echo = null;
    private array $log = [];

    public function __construct(private PDO $db, private SparqlService $service, private FileStore $files) {}

    public function onProgress(?callable $echo): self
    {
        $this->echo = $echo ? \Closure::fromCallable($echo) : null;
        return $this;
    }

    private function say(string $msg): void
    {
        $line = date('H:i:s') . ' ' . $msg;
        $this->log[] = $line;
        if ($this->echo) {
            ($this->echo)($line);
        }
    }

    /**
     * @param array<int, array{name:string, path:string[]}>|null $columns null = colonne salvate o proposte dal profilo
     * @return array riga di gwc.sparql_downloads
     */
    public function download(int $groupId, string $format = 'csv', ?array $columns = null, ?int $maxSubjects = null): array
    {
        $g = $this->service->group($groupId) ?? throw new \InvalidArgumentException("Gruppo $groupId inesistente");
        $this->log = [];
        if ($g['kind'] === 'dataset') {
            $format = 'file';
        }
        if ($format === 'csv') {
            if ($columns === null) {
                $columns = $g['columns'];
                if (!$columns) {
                    if (!$g['profile']) {
                        $this->say('Calcolo il profilo del gruppo...');
                        $g['profile'] = $this->service->profile($groupId);
                    }
                    $columns = SparqlService::defaultColumns($g['profile']);
                }
            }
            $columns = SparqlService::uniqueNames($columns);
        }
        $st = $this->db->prepare("INSERT INTO gwc.sparql_downloads (group_id, format, columns) VALUES (?, ?, CAST(? AS jsonb)) RETURNING id");
        $st->execute([$groupId, $format, json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $id = (int) $st->fetchColumn();
        $stats = ['subjects' => 0, 'rows' => 0, 'pages' => 0, 'file' => null, 'geojson' => null, 'query' => null];
        $status = 'ok';
        $error = null;
        try {
            $stats = match ($format) {
                'csv'  => $this->csv($g, $id, $columns ?? [], $maxSubjects),
                'rdf'  => $this->rdf($g, $id, $maxSubjects),
                'file' => $this->files($g, $id),
                default => throw new \InvalidArgumentException("Formato sconosciuto: $format"),
            };
            if ($maxSubjects !== null && $stats['subjects'] >= $maxSubjects) {
                $status = 'partial';
                $this->say("Fermato a $maxSubjects oggetti come richiesto.");
            }
        } catch (\Throwable $e) {
            $status = $stats['subjects'] > 0 || ($stats['file'] ?? null) ? 'partial' : 'error';
            $error = $e->getMessage();
            $this->say('ERRORE: ' . $error);
            $stats = $this->partial ?? $stats;
        }
        $this->db->prepare("UPDATE gwc.sparql_downloads SET status = ?, error = ?, finished_at = now(), n_subjects = ?, n_rows = ?,
                                   n_pages = ?, file_id = ?, geojson_file_id = ?, query = ?, log = ? WHERE id = ?")
                 ->execute([$status, $error, $stats['subjects'], $stats['rows'], $stats['pages'], $stats['file'], $stats['geojson'],
                            $stats['query'], implode("\n", $this->log), $id]);
        $r = $this->db->query("SELECT * FROM gwc.sparql_downloads WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        return $r;
    }

    /** statistiche parziali, se lo scaricamento si interrompe a meta' */
    private ?array $partial = null;

    private function dir(array $g): string
    {
        return 'sparql/' . (int) $g['endpoint_id'] . '/' . (int) $g['id'];
    }

    private function label(array $g): string
    {
        return $g['label'] ?: QueryBuilder::localName($g['item_iri']);
    }

    private function csv(array $g, int $downloadId, array $columns, ?int $maxSubjects): array
    {
        $e = $this->service->endpoint((int) $g['endpoint_id']);
        $client = $this->service->client($e);
        $b = $this->service->builder($e);
        $pageSize = max(10, (int) ($e['options']['page_size'] ?? 2000));
        $maxRows = (int) ($e['options']['max_rows'] ?? 10000);
        $offsetMode = ($e['options']['paging'] ?? 'keyset') === 'offset';
        $sep = (string) Config::get('csv_separator', ',');

        $rel = $this->files->newPath($this->dir($g), $this->label($g), 'csv');
        $fh = fopen($this->files->absolute($rel), 'wb');
        fwrite($fh, "\xEF\xBB\xBF");   // BOM: Excel riconosce l'UTF-8
        fputcsv($fh, array_merge(['iri'], array_column($columns, 'name')), $sep, '"', '');

        // GeoJSON se ci sono coordinate o geometrie
        $names = array_map(fn($c) => QueryBuilder::localName(end($c['path'])), $columns);
        $lat = $this->findCol($names, '/^(lat|latitude|latitudine)$/i');
        $lon = $this->findCol($names, '/^(long|lon|lng|longitude|longitudine)$/i');
        $wkt = $this->findCol($names, '/(aswkt|wkt|geometry|geometria)$/i');
        $geoRel = null;
        $gh = null;
        if (($lat !== null && $lon !== null) || $wkt !== null) {
            $geoRel = substr($rel, 0, -4) . '.geojson';
            $gh = fopen($this->files->absolute($geoRel), 'wb');
            fwrite($gh, '{"type":"FeatureCollection","features":[' . "\n");
        }
        $toGeo = $this->db->prepare("SELECT ST_AsGeoJSON(ST_GeomFromText(?, 4326), 7)");
        $nFeatures = 0;

        $stats = ['subjects' => 0, 'rows' => 0, 'pages' => 0, 'file' => null, 'geojson' => null, 'query' => null];
        $after = null;
        $offset = 0;
        try {
            while (true) {
                $limit = $maxSubjects !== null ? min($pageSize, $maxSubjects - $stats['subjects']) : $pageSize;
                if ($limit <= 0) {
                    break;
                }
                $q = $offsetMode ? $b->page($g['graph_iri'], $g['item_iri'], $columns, $limit, null, $offset)
                                 : $b->page($g['graph_iri'], $g['item_iri'], $columns, $limit, $after);
                $stats['query'] ??= $q;
                $res = $client->select($q);
                if (count($res['rows']) >= $maxRows && $pageSize > 50) {
                    // il server ha tagliato i risultati (troppi valori per oggetto): pagine piu' piccole
                    $pageSize = max(50, intdiv($pageSize, 4));
                    $this->say("Risposta troppo grande ({$maxRows} righe): riprovo con pagine da $pageSize oggetti");
                    continue;
                }
                // un oggetto puo' avere piu' valori: si raccolgono per oggetto
                $bySubject = [];
                foreach ($res['rows'] as $r) {
                    $s = $r['s'] ?? '';
                    if ($s === '') {
                        continue;
                    }
                    foreach ($columns as $i => $c) {
                        $v = $r["c$i"] ?? null;
                        if ($v !== null && $v !== '') {
                            $bySubject[$s][$i][$v] = true;
                        } else {
                            $bySubject[$s][$i] ??= [];
                        }
                    }
                    $bySubject[$s] ??= [];
                }
                $subjects = array_keys($bySubject);
                usort($subjects, 'strcmp');
                foreach ($subjects as $s) {
                    $vals = [];
                    foreach ($columns as $i => $c) {
                        $vals[$i] = implode(' | ', array_keys($bySubject[$s][$i] ?? []));
                    }
                    fputcsv($fh, array_merge([$s], $vals), $sep, '"', '');
                    if ($gh) {
                        $geom = null;
                        if ($lat !== null && $lon !== null && is_numeric($y = str_replace(',', '.', explode(' | ', $vals[$lat])[0]))
                            && is_numeric($x = str_replace(',', '.', explode(' | ', $vals[$lon])[0]))) {
                            $geom = '{"type":"Point","coordinates":[' . (float) $x . ',' . (float) $y . ']}';
                        } elseif ($wkt !== null && ($w = $this->wkt(explode(' | ', $vals[$wkt])[0])) !== null) {
                            try {
                                $toGeo->execute([$w]);
                                $geom = $toGeo->fetchColumn() ?: null;
                            } catch (\PDOException) {
                                $geom = null;   // geometria non valida: l'oggetto resta solo nel CSV
                            }
                        }
                        if ($geom) {
                            $props = ['iri' => $s];
                            foreach ($columns as $i => $c) {
                                $props[$c['name']] = $vals[$i];
                            }
                            fwrite($gh, ($nFeatures ? ",\n" : '') . '{"type":"Feature","geometry":' . $geom . ',"properties":'
                                        . json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '}');
                            $nFeatures++;
                        }
                    }
                }
                $stats['subjects'] += count($subjects);
                $stats['rows'] += count($res['rows']);
                $stats['pages']++;
                $this->partial = $stats;
                $this->progress($downloadId, $stats);
                $this->say("Pagina {$stats['pages']}: " . count($subjects) . " oggetti ({$stats['subjects']} in tutto, {$client->lastMs} ms)");
                if (count($subjects) < $limit) {
                    break;
                }
                $after = end($subjects);
                $offset += count($subjects);
            }
        } finally {
            fclose($fh);
            if ($gh) {
                fwrite($gh, "\n]}\n");
                fclose($gh);
            }
            $stats['file'] = $this->files->register($rel, 'sparql', 'text/csv', $stats['subjects'],
                ['group_id' => (int) $g['id'], 'download_id' => $downloadId, 'columns' => $columns], basename($rel));
            if ($geoRel !== null) {
                if ($nFeatures > 0) {
                    $stats['geojson'] = $this->files->register($geoRel, 'sparql', 'application/geo+json', $nFeatures,
                        ['group_id' => (int) $g['id'], 'download_id' => $downloadId], basename($geoRel));
                    $this->say("GeoJSON: $nFeatures oggetti con coordinate o geometria");
                } else {
                    @unlink($this->files->absolute($geoRel));
                }
            }
            $this->partial = $stats;
        }
        return $stats;
    }

    private function rdf(array $g, int $downloadId, ?int $maxSubjects): array
    {
        $e = $this->service->endpoint((int) $g['endpoint_id']);
        $client = $this->service->client($e);
        $b = $this->service->builder($e);
        $pageSize = max(10, (int) ($e['options']['page_size'] ?? 2000));
        $stats = ['subjects' => 0, 'rows' => 0, 'pages' => 0, 'file' => null, 'geojson' => null, 'query' => null];
        $rel = null;
        $fh = null;
        $type = '';
        $after = null;
        try {
            while (true) {
                $limit = $maxSubjects !== null ? min($pageSize, $maxSubjects - $stats['subjects']) : $pageSize;
                if ($limit <= 0) {
                    break;
                }
                $subjects = array_column($client->select($b->subjectsQuery($g['graph_iri'], $g['item_iri'], $limit, $after))['rows'], 's');
                if (!$subjects) {
                    break;
                }
                $q = $b->pageRdf($g['graph_iri'], $g['item_iri'], $limit, $after);
                $stats['query'] ??= $q;
                [$body, $type] = $client->graph($q);
                if ($fh === null) {
                    $ext = match (true) {
                        str_contains($type, 'turtle') => 'ttl',
                        str_contains($type, 'rdf+xml') => 'rdf',
                        default => 'nt',
                    };
                    $rel = $this->files->newPath($this->dir($g), $this->label($g), $ext);
                    $fh = fopen($this->files->absolute($rel), 'wb');
                }
                fwrite($fh, rtrim($body) . "\n");
                $stats['subjects'] += count($subjects);
                $stats['rows'] += substr_count($body, "\n");
                $stats['pages']++;
                $this->partial = $stats;
                $this->progress($downloadId, $stats);
                $this->say("Pagina {$stats['pages']}: " . count($subjects) . " oggetti ({$stats['subjects']} in tutto)");
                if (count($subjects) < $limit) {
                    break;
                }
                usort($subjects, 'strcmp');
                $after = end($subjects);
            }
        } finally {
            if ($fh) {
                fclose($fh);
                $stats['file'] = $this->files->register($rel, 'sparql', $type ?: 'application/n-triples', $stats['rows'],
                    ['group_id' => (int) $g['id'], 'download_id' => $downloadId], basename($rel));
                $this->partial = $stats;
            }
        }
        return $stats;
    }

    /** Dataset DCAT: scarica i file indicati (il primo che risponde). */
    private function files(array $g, int $downloadId): array
    {
        $stats = ['subjects' => 0, 'rows' => 0, 'pages' => 0, 'file' => null, 'geojson' => null, 'query' => null];
        $urls = \GeoWebCrawler\Repository\ResourceRepository::parsePgArray($g['urls']);
        if (!$urls) {
            throw new \RuntimeException('Il dataset non indica indirizzi di scaricamento');
        }
        foreach ($urls as $url) {
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'dat';
            $ext = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'dat';
            $rel = $this->files->newPath($this->dir($g), $this->label($g), $ext);
            $this->say("Scarico $url");
            [$ok, $type, $err] = $this->fetchToFile($url, $this->files->absolute($rel));
            if (!$ok) {
                @unlink($this->files->absolute($rel));
                $this->say("Non riuscito: $err");
                continue;
            }
            $stats['file'] = $this->files->register($rel, 'sparql', $type, null, ['group_id' => (int) $g['id'], 'url' => $url], basename($rel));
            $stats['pages'] = 1;
            $this->say('Salvato ' . $rel);
            return $stats;
        }
        throw new \RuntimeException('Nessun indirizzo di scaricamento ha risposto');
    }

    /** @return array{0:bool,1:?string,2:?string} ok, tipo, errore */
    private function fetchToFile(string $url, string $path): array
    {
        $fh = fopen($path, 'wb');
        $ch = curl_init($url);
        $opts = [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 20,
                 CURLOPT_TIMEOUT => 3600, CURLOPT_USERAGENT => (string) Config::get('http.user_agent', HttpClient::DEFAULT_UA),
                 CURLOPT_FAILONERROR => true];
        if ($ca = Config::get('http.ca_bundle')) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch) !== false;
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
        $err = $ok ? null : curl_error($ch);
        fclose($fh);
        return [$ok, $type, $err];
    }

    private function progress(int $id, array $s): void
    {
        $this->db->prepare("UPDATE gwc.sparql_downloads SET n_subjects = ?, n_rows = ?, n_pages = ? WHERE id = ?")
                 ->execute([$s['subjects'], $s['rows'], $s['pages'], $id]);
    }

    private function findCol(array $names, string $re): ?int
    {
        foreach ($names as $i => $n) {
            if (preg_match($re, $n)) {
                return $i;
            }
        }
        return null;
    }

    /** Testo WKT (anche nella forma GeoSPARQL con il sistema di riferimento davanti), o null. */
    private function wkt(string $v): ?string
    {
        $v = trim((string) preg_replace('/^<[^>]+>\s*/', '', trim($v)));
        return preg_match('/^(POINT|LINESTRING|POLYGON|MULTIPOINT|MULTILINESTRING|MULTIPOLYGON|GEOMETRYCOLLECTION)\s*(Z\s*)?\(/i', $v) ? $v : null;
    }
}
