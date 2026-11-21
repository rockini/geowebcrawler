<?php
declare(strict_types=1);

namespace GeoWebCrawler\Web;

use GeoWebCrawler\Sparql\Downloader;
use GeoWebCrawler\Sparql\QueryBuilder;
use GeoWebCrawler\Sparql\SparqlService;
use GeoWebCrawler\Storage\FileStore;
use PDO;

/** Pagine e azioni dello strumento SPARQL (?p=sparql, sparql_group, sparql_query, files, file). */
final class SparqlPages
{
    private SparqlService $svc;

    public function __construct(private PDO $db)
    {
        $this->svc = new SparqlService($db);
    }

    // ------------------------------------------------------------------ pagine

    public function index(): void
    {
        $endpoints = $this->db->query("SELECT e.*, (SELECT count(*) FROM gwc.sparql_groups g WHERE g.endpoint_id = e.id) AS n_groups
                                         FROM gwc.sparql_endpoints e ORDER BY e.id")->fetchAll();
        $epId = (int) ($_GET['ep'] ?? ($endpoints[0]['id'] ?? 0));
        $f = [
            'ep'   => $epId,
            'kw'   => trim((string) ($_GET['kw'] ?? '')),
            'mode' => (string) ($_GET['mode'] ?? 'text'),
            'geo'  => !empty($_GET['geo']),
            'q'    => trim((string) ($_GET['q'] ?? '')),
            'only' => (string) ($_GET['only'] ?? ''),   // '' | geo | selected | dataset
        ];
        // query preparata (non ancora spedita), da mostrare e modificare
        $prepared = null;
        if (!empty($_GET['prepare']) && $f['kw'] !== '' && $epId) {
            try {
                $prepared = $this->svc->searchQuery($this->svc->endpoint($epId), $f['kw'], $f['mode'], $f['geo']);
            } catch (\Throwable $e) {
                Session::flash($e->getMessage(), 'err');
            }
        }
        $w = ['g.endpoint_id = :ep'];
        $a = [':ep' => $epId];
        if ($f['q'] !== '') {
            $w[] = "(g.label ILIKE :q1 OR g.item_iri ILIKE :q2 OR array_to_string(g.keywords, ' ') ILIKE :q3 OR g.sample_text ILIKE :q4)";
            foreach ([1, 2, 3, 4] as $i) {
                $a[":q$i"] = '%' . $f['q'] . '%';
            }
        }
        $w[] = match ($f['only']) {
            'geo'      => "g.geo <> '{}'",
            'selected' => 'g.selected',
            'dataset'  => "g.kind = 'dataset'",
            default    => 'TRUE',
        };
        $st = $this->db->prepare("SELECT g.*,
                                         (SELECT d.status FROM gwc.sparql_downloads d WHERE d.group_id = g.id ORDER BY d.id DESC LIMIT 1) AS last_status,
                                         (SELECT d.n_subjects FROM gwc.sparql_downloads d WHERE d.group_id = g.id ORDER BY d.id DESC LIMIT 1) AS last_n
                                    FROM gwc.sparql_groups g WHERE " . implode(' AND ', $w) . "
                                   ORDER BY g.selected DESC, g.last_seen_at DESC, g.n_instances DESC NULLS LAST LIMIT 500");
        $st->execute($a);
        $groups = $st->fetchAll();
        $searches = $this->db->prepare("SELECT * FROM gwc.sparql_searches WHERE endpoint_id = ? ORDER BY id DESC LIMIT 10");
        $searches->execute([$epId]);
        $searches = $searches->fetchAll();
        $downloads = $this->db->prepare("SELECT d.*, g.label, g.item_iri, f.path, gf.path AS geo_path, f.size_bytes
                                           FROM gwc.sparql_downloads d JOIN gwc.sparql_groups g ON g.id = d.group_id
                                           LEFT JOIN gwc.files f ON f.id = d.file_id LEFT JOIN gwc.files gf ON gf.id = d.geojson_file_id
                                          WHERE g.endpoint_id = ? ORDER BY d.id DESC LIMIT 10");
        $downloads->execute([$epId]);
        $downloads = $downloads->fetchAll();
        View::render('sparql', compact('endpoints', 'epId', 'f', 'prepared', 'groups', 'searches', 'downloads'), 'SPARQL');
    }

    public function group(int $id): void
    {
        $g = $this->svc->group($id);
        if (!$g) {
            http_response_code(404);
            View::render('notfound', [], 'Non trovato');
            return;
        }
        $columns = $g['columns'] ?: ($g['profile'] ? SparqlService::defaultColumns($g['profile']) : []);
        $e = $this->svc->endpoint((int) $g['endpoint_id']);
        $preview = $g['kind'] === 'class' && $columns
            ? QueryBuilder::withPrefixes($this->svc->builder($e)->page($g['graph_iri'], $g['item_iri'], $columns, (int) ($e['options']['page_size'] ?? 2000)))
            : null;
        $st = $this->db->prepare("SELECT d.*, f.path, f.size_bytes, gf.path AS geo_path, gf.n_rows AS geo_rows
                                    FROM gwc.sparql_downloads d LEFT JOIN gwc.files f ON f.id = d.file_id
                                    LEFT JOIN gwc.files gf ON gf.id = d.geojson_file_id
                                   WHERE d.group_id = ? ORDER BY d.id DESC");
        $st->execute([$id]);
        $downloads = $st->fetchAll();
        View::render('sparql_group', compact('g', 'columns', 'preview', 'downloads'), $g['label'] ?: QueryBuilder::localName($g['item_iri']));
    }

    public function query(?array $result = null, string $query = '', int $epId = 0, ?string $error = null): void
    {
        $endpoints = $this->db->query("SELECT id, name, url FROM gwc.sparql_endpoints ORDER BY id")->fetchAll();
        $epId = $epId ?: (int) ($_GET['ep'] ?? ($endpoints[0]['id'] ?? 0));
        if ($query === '' && isset($_GET['search'])) {
            $s = $this->db->prepare("SELECT query, endpoint_id FROM gwc.sparql_searches WHERE id = ?");
            $s->execute([(int) $_GET['search']]);
            if ($row = $s->fetch()) {
                [$query, $epId] = [$row['query'], (int) $row['endpoint_id']];
            }
        }
        if ($query === '' && isset($_GET['q'])) {
            $query = (string) $_GET['q'];
        }
        $templates = QueryBuilder::templates();
        $prefixes = QueryBuilder::PREFIXES;
        View::render('sparql_query', compact('endpoints', 'epId', 'query', 'result', 'error', 'templates', 'prefixes'), 'Editor SPARQL');
    }

    /** Esecuzione dall'editor: il risultato si mostra subito (senza redirect). */
    public function runFromEditor(): void
    {
        $q = (string) ($_POST['query'] ?? '');
        $ep = (int) ($_POST['endpoint_id'] ?? 0);
        $result = null;
        $error = null;
        try {
            set_time_limit(0);
            $result = $this->svc->run($ep, $q);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $this->query($result, $q, $ep, $error);
    }

    public function files(): void
    {
        $kind = (string) ($_GET['kind'] ?? '');
        $st = $this->db->prepare("SELECT * FROM gwc.files WHERE (? = '' OR kind = ?) ORDER BY id DESC LIMIT 500");
        $st->execute([$kind, $kind]);
        $files = $st->fetchAll();
        $root = (new FileStore($this->db))->root();
        $total = (int) $this->db->query("SELECT COALESCE(sum(size_bytes), 0) FROM gwc.files")->fetchColumn();
        View::render('files', compact('files', 'root', 'total', 'kind'), 'File salvati');
    }

    /** Invia al browser un file salvato. */
    public function file(int $id): void
    {
        $store = new FileStore($this->db);
        $f = $store->get($id);
        $abs = $f ? $store->absolute($f['path']) : null;
        if (!$abs || !is_file($abs)) {
            http_response_code(404);
            echo 'File non trovato';
            return;
        }
        header('Content-Type: ' . ($f['media_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($f['path'])) . '"');
        readfile($abs);
    }

    // ------------------------------------------------------------------ azioni

    public function action(string $action): ?string
    {
        switch ($action) {
            case 'sparql_endpoint_save':
                $id = (int) ($_POST['id'] ?? 0);
                $url = trim((string) ($_POST['url'] ?? ''));
                if (!preg_match('#^https?://#i', $url)) {
                    throw new \InvalidArgumentException("Indirizzo dell'endpoint non valido");
                }
                $opts = json_decode((string) ($_POST['options'] ?? '{}'), true);
                if (!is_array($opts)) {
                    throw new \InvalidArgumentException('Opzioni: JSON non valido');
                }
                $args = [trim((string) ($_POST['name'] ?? '')) ?: (string) parse_url($url, PHP_URL_HOST), $url,
                         trim((string) ($_POST['description'] ?? '')) ?: null, json_encode($opts)];
                if ($id) {
                    $this->db->prepare("UPDATE gwc.sparql_endpoints SET name = ?, url = ?, description = ?, options = CAST(? AS jsonb) WHERE id = ?")
                             ->execute([...$args, $id]);
                } else {
                    $st = $this->db->prepare("INSERT INTO gwc.sparql_endpoints (name, url, description, options) VALUES (?, ?, ?, CAST(? AS jsonb)) RETURNING id");
                    $st->execute($args);
                    $id = (int) $st->fetchColumn();
                }
                Session::flash('Endpoint salvato.');
                return View::url('sparql', ['ep' => $id]);

            case 'sparql_endpoint_delete':
                $this->db->prepare("DELETE FROM gwc.sparql_endpoints WHERE id = ?")->execute([(int) $_POST['id']]);
                Session::flash('Endpoint eliminato (con i suoi gruppi; i file restano nella cartella).');
                return View::url('sparql');

            case 'sparql_search':
                set_time_limit(0);
                $ep = (int) ($_POST['endpoint_id'] ?? 0);
                $kw = trim((string) ($_POST['kw'] ?? ''));
                $mode = (string) ($_POST['mode'] ?? 'text');
                $r = $this->svc->search($ep, $kw, $mode, !empty($_POST['geo']), (string) ($_POST['query'] ?? '') ?: null);
                Session::flash("Ricerca completata: {$r['rows']} risultati, {$r['new']} gruppi nuovi.");
                return View::url('sparql', ['ep' => $ep, 'q' => '', 'kw' => $kw, 'mode' => $mode]);

            case 'sparql_bulk':
                set_time_limit(0);
                ignore_user_abort(true);
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $op = (string) ($_POST['op'] ?? '');
                if (!$ids) {
                    throw new \InvalidArgumentException('Seleziona almeno un gruppo');
                }
                $arr = '{' . implode(',', $ids) . '}';
                $msg = match ($op) {
                    'select'   => $this->exec("UPDATE gwc.sparql_groups SET selected = true WHERE id = ANY (CAST(? AS bigint[]))", $arr) . ' gruppi selezionati.',
                    'unselect' => $this->exec("UPDATE gwc.sparql_groups SET selected = false WHERE id = ANY (CAST(? AS bigint[]))", $arr) . ' gruppi tolti dalla selezione.',
                    'delete'   => $this->exec("DELETE FROM gwc.sparql_groups WHERE id = ANY (CAST(? AS bigint[]))", $arr) . ' gruppi eliminati (i file scaricati restano).',
                    'profile'  => $this->bulkProfile($ids),
                    'download' => $this->bulkDownload($ids, (string) ($_POST['format'] ?? 'csv')),
                    default    => throw new \InvalidArgumentException('Operazione sconosciuta'),
                };
                Session::flash($msg);
                return $this->back(View::url('sparql'));

            case 'sparql_profile':
                set_time_limit(0);
                $id = (int) $_POST['id'];
                $path = array_values(array_filter(explode(' ', trim((string) ($_POST['path'] ?? '')))));
                $this->svc->profile($id, $path, !$path);
                Session::flash($path ? 'Livello successivo esplorato.' : 'Profilo calcolato.');
                return View::url('sparql_group', ['id' => $id]);

            case 'sparql_columns':
                $id = (int) $_POST['id'];
                $cols = [];
                foreach ((array) ($_POST['col'] ?? []) as $path) {
                    $p = array_values(array_filter(explode(' ', (string) $path)));
                    if ($p) {
                        $name = trim((string) ($_POST['name'][$path] ?? '')) ?: implode('_', array_map([QueryBuilder::class, 'localName'], $p));
                        $cols[] = ['name' => $name, 'path' => $p];
                    }
                }
                foreach (preg_split('/\R/', trim((string) ($_POST['extra'] ?? ''))) ?: [] as $line) {
                    // colonne aggiunte a mano: un percorso di IRI separati da spazi o "/"
                    $p = array_values(array_filter(preg_split('#\s+|\s*/\s*(?=<|https?:)#', trim(str_replace(['<', '>'], ' ', $line))) ?: []));
                    if ($p && !array_filter($p, fn($x) => !preg_match('#^https?://#', $x))) {
                        $cols[] = ['name' => implode('_', array_map([QueryBuilder::class, 'localName'], $p)), 'path' => $p];
                    }
                }
                $this->svc->saveColumns($id, $cols);
                Session::flash(count($cols) . ' colonne salvate.');
                return View::url('sparql_group', ['id' => $id]);

            case 'sparql_download':
                set_time_limit(0);
                ignore_user_abort(true);
                $id = (int) $_POST['id'];
                $max = (int) ($_POST['max'] ?? 0);
                $r = $this->downloader()->download($id, (string) ($_POST['format'] ?? 'csv'), null, $max > 0 ? $max : null);
                Session::flash("Scaricamento: {$r['status']}, {$r['n_subjects']} oggetti" . ($r['error'] ? " - {$r['error']}" : ''),
                               $r['status'] === 'ok' ? 'ok' : ($r['status'] === 'partial' ? 'warn' : 'err'));
                return View::url('sparql_group', ['id' => $id]);

            case 'sparql_save_groups':
                set_time_limit(0);
                $ep = (int) $_POST['endpoint_id'];
                $r = $this->svc->search($ep, trim((string) ($_POST['kw'] ?? '')) ?: 'query manuale', 'query', false, (string) $_POST['query']);
                Session::flash("{$r['rows']} risultati, {$r['new']} gruppi nuovi (servono le colonne ?class oppure ?ds; facoltative ?g ?label ?n).");
                return View::url('sparql', ['ep' => $ep]);

            case 'sparql_save_csv':
                set_time_limit(0);
                $ep = (int) $_POST['endpoint_id'];
                $r = $this->svc->run($ep, (string) $_POST['query']);
                $store = new FileStore($this->db);
                if (isset($r['rdf'])) {
                    $rel = $store->newPath("sparql/$ep/query", 'query', str_contains($r['type'], 'turtle') ? 'ttl' : 'nt');
                    file_put_contents($store->absolute($rel), $r['rdf']);
                    $fid = $store->register($rel, 'sparql', $r['type'], substr_count($r['rdf'], "\n"), ['query' => $_POST['query']]);
                } else {
                    $rel = $store->newPath("sparql/$ep/query", 'query', 'csv');
                    $fh = fopen($store->absolute($rel), 'wb');
                    fwrite($fh, "\xEF\xBB\xBF");
                    $sep = (string) \GeoWebCrawler\Config::get('csv_separator', ',');
                    fputcsv($fh, $r['vars'], $sep, '"', '');
                    foreach ($r['rows'] as $row) {
                        fputcsv($fh, array_map(fn($v) => $row[$v] ?? '', $r['vars']), $sep, '"', '');
                    }
                    fclose($fh);
                    $fid = $store->register($rel, 'sparql', 'text/csv', count($r['rows']), ['query' => $_POST['query']]);
                }
                Session::flash("Risultato salvato nel file $rel.");
                return View::url('files', ['hl' => $fid]);

            case 'file_delete':
                (new FileStore($this->db))->delete((int) $_POST['id']);
                Session::flash('File cancellato.');
                return View::url('files');
        }
        return null;
    }

    private function exec(string $sql, string $arr): int
    {
        $st = $this->db->prepare($sql);
        $st->execute([$arr]);
        return $st->rowCount();
    }

    private function downloader(): Downloader
    {
        return new Downloader($this->db, $this->svc, new FileStore($this->db));
    }

    private function bulkProfile(array $ids): string
    {
        $ok = 0;
        $err = [];
        foreach ($ids as $id) {
            try {
                $this->svc->profile($id);
                $ok++;
            } catch (\Throwable $e) {
                $err[] = "#$id: " . $e->getMessage();
            }
        }
        return "Profilo calcolato per $ok gruppi." . ($err ? ' Errori: ' . implode('; ', array_slice($err, 0, 3)) : '');
    }

    private function bulkDownload(array $ids, string $format): string
    {
        $dl = $this->downloader();
        $out = [];
        foreach ($ids as $id) {
            $r = $dl->download($id, $format);
            $out[] = "#$id {$r['status']} ({$r['n_subjects']})";
        }
        return 'Scaricamenti: ' . implode(', ', $out) . '. I file sono nella pagina "File salvati".';
    }

    private function back(string $default): string
    {
        $b = (string) ($_POST['back'] ?? '');
        return str_starts_with($b, '?') ? $b : $default;
    }
}
