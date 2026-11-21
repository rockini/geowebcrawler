<?php
declare(strict_types=1);

namespace GeoWebCrawler\Sparql;

use PDO;

/**
 * Ricerca dei gruppi di dati in un endpoint SPARQL e loro profilo (proprieta' usate, dati geografici).
 * Lo scaricamento e' in Downloader.
 */
final class SparqlService
{
    public const MODES = [
        'text'     => 'Oggetti con nome o descrizione che contiene le parole',
        'classes'  => 'Classi (tipi di oggetti) con il nome che contiene le parole',
        'datasets' => 'Dataset descritti nel catalogo (DCAT/VoID)',
    ];

    /** Riconoscimento dei dati geografici dal nome della proprieta'. */
    private const GEO_PATTERNS = [
        'coordinate' => '/^(lat|latitude|latitudine|long|lon|lng|longitude|longitudine)$/i',
        'wkt'        => '/(aswkt|wkt|geometry|geometria|hasgeometry|geojson)$/i',
        'indirizzo'  => '/(address|indirizzo|street|streetnumber|housenumber|civico|postalcode|postcode|cap|toponym|fulladdress|hasnumber|sitedaddress|siteaddress|hascity|municipality|comune|location)$/i',
    ];

    public function __construct(private PDO $db) {}

    public function endpoint(int $id): array
    {
        $st = $this->db->prepare("SELECT * FROM gwc.sparql_endpoints WHERE id = ?");
        $st->execute([$id]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) {
            throw new \InvalidArgumentException("Endpoint SPARQL $id inesistente");
        }
        $e['options'] = json_decode((string) $e['options'], true) ?: [];
        return $e;
    }

    public function group(int $id): ?array
    {
        $st = $this->db->prepare("SELECT g.*, e.name AS endpoint_name, e.url AS endpoint_url
                                    FROM gwc.sparql_groups g JOIN gwc.sparql_endpoints e ON e.id = g.endpoint_id WHERE g.id = ?");
        $st->execute([$id]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if ($g) {
            $g['profile'] = json_decode((string) $g['profile'], true) ?: [];
            $g['columns'] = json_decode((string) $g['columns'], true) ?: null;
        }
        return $g ?: null;
    }

    public function client(array $endpoint): SparqlClient
    {
        return new SparqlClient($endpoint);
    }

    public function builder(array $endpoint): QueryBuilder
    {
        return new QueryBuilder((bool) ($endpoint['options']['virtuoso'] ?? false));
    }

    /** Query che verrebbe spedita per una ricerca (per mostrarla e farla modificare). */
    public function searchQuery(array $endpoint, string $keywords, string $mode, bool $geoOnly, int $limit = 200): string
    {
        $words = QueryBuilder::keywords($keywords);
        if (!$words) {
            throw new \InvalidArgumentException('Scrivi almeno una parola chiave (di 2 o piu\' lettere)');
        }
        $b = $this->builder($endpoint);
        return match ($mode) {
            'classes'  => $b->searchClasses($words, $geoOnly, $limit),
            'datasets' => $b->searchDatasets($words, $limit),
            default    => $b->searchText($words, $geoOnly, $limit),
        };
    }

    /**
     * Esegue una ricerca (query generata o scritta a mano) e salva i gruppi trovati.
     * Le colonne riconosciute nei risultati sono: g, class oppure ds, label, n, esempio, testo, descrizione, urls.
     * @return array{search_id:int, rows:int, new:int, groups:int[]}
     */
    public function search(int $endpointId, string $keywords, string $mode, bool $geoOnly, ?string $query = null): array
    {
        $e = $this->endpoint($endpointId);
        $query = trim((string) $query) !== '' ? (string) $query : $this->searchQuery($e, $keywords, $mode, $geoOnly);
        $st = $this->db->prepare("INSERT INTO gwc.sparql_searches (endpoint_id, keywords, mode, geo_only, query)
                                  VALUES (?, ?, ?, ?, ?) RETURNING id");
        $st->execute([$endpointId, $keywords, $mode, $geoOnly ? 't' : 'f', $query]);
        $searchId = (int) $st->fetchColumn();

        $client = $this->client($e);
        try {
            $res = $client->select($query);
        } catch (\Throwable $ex) {
            $this->db->prepare("UPDATE gwc.sparql_searches SET status = 'error', error = ?, ms = ? WHERE id = ?")
                     ->execute([$ex->getMessage(), $client->lastMs, $searchId]);
            $this->db->prepare("UPDATE gwc.sparql_endpoints SET last_error = ? WHERE id = ?")->execute([$ex->getMessage(), $endpointId]);
            throw $ex;
        }
        $this->db->prepare("UPDATE gwc.sparql_endpoints SET last_ok_at = now(), last_error = NULL WHERE id = ?")->execute([$endpointId]);

        $words = QueryBuilder::keywords($keywords);
        $kwArr = '{' . implode(',', array_map(fn($w) => '"' . addcslashes($w, '"\\') . '"', $words)) . '}';
        $up = $this->db->prepare(
            "INSERT INTO gwc.sparql_groups (endpoint_id, kind, graph_iri, item_iri, label, description, n_instances,
                                            sample_iri, sample_text, urls, keywords, first_search_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS text[]), CAST(? AS text[]), ?)
             ON CONFLICT (endpoint_id, graph_iri, item_iri) DO UPDATE SET
                 label       = COALESCE(EXCLUDED.label, gwc.sparql_groups.label),
                 description = COALESCE(EXCLUDED.description, gwc.sparql_groups.description),
                 n_instances = COALESCE(EXCLUDED.n_instances, gwc.sparql_groups.n_instances),
                 sample_iri  = COALESCE(EXCLUDED.sample_iri, gwc.sparql_groups.sample_iri),
                 sample_text = COALESCE(EXCLUDED.sample_text, gwc.sparql_groups.sample_text),
                 urls        = CASE WHEN EXCLUDED.urls = '{}' THEN gwc.sparql_groups.urls ELSE EXCLUDED.urls END,
                 keywords    = ARRAY(SELECT DISTINCT unnest(gwc.sparql_groups.keywords || EXCLUDED.keywords)),
                 last_seen_at = now()
             RETURNING id, (xmax = 0) AS inserted");
        $new = 0;
        $ids = [];
        foreach ($res['rows'] as $r) {
            $isDataset = isset($r['ds']);
            $item = $r['class'] ?? $r['ds'] ?? $r['type'] ?? null;
            if ($item === null || $item === '') {
                continue;
            }
            $urls = array_values(array_filter(preg_split('/\s+/', (string) ($r['urls'] ?? '')) ?: []));
            $up->execute([
                $endpointId, $isDataset ? 'dataset' : 'class', (string) ($r['g'] ?? ''), $item,
                ($r['label'] ?? '') !== '' ? $r['label'] : null,
                ($r['descrizione'] ?? '') !== '' ? mb_substr($r['descrizione'], 0, 2000) : null,
                isset($r['n']) && is_numeric($r['n']) ? (int) $r['n'] : null,
                ($r['esempio'] ?? '') !== '' ? $r['esempio'] : null,
                ($r['testo'] ?? '') !== '' ? mb_substr($r['testo'], 0, 500) : null,
                '{' . implode(',', array_map(fn($u) => '"' . addcslashes($u, '"\\') . '"', $urls)) . '}',
                $kwArr, $searchId,
            ]);
            $row = $up->fetch(PDO::FETCH_ASSOC);
            $ids[] = (int) $row['id'];
            $new += $row['inserted'] ? 1 : 0;
        }
        $this->db->prepare("UPDATE gwc.sparql_searches SET status = 'ok', ms = ?, n_rows = ?, n_new = ? WHERE id = ?")
                 ->execute([$client->lastMs, count($res['rows']), $new, $searchId]);
        return ['search_id' => $searchId, 'rows' => count($res['rows']), 'new' => $new, 'groups' => $ids];
    }

    /** Esegue una query libera e restituisce i risultati (al massimo $max righe). */
    public function run(int $endpointId, string $query): array
    {
        $e = $this->endpoint($endpointId);
        $client = $this->client($e);
        if (preg_match('/^\s*(PREFIX[^\n]*\n\s*|#[^\n]*\n\s*)*(CONSTRUCT|DESCRIBE)\b/i', $query)) {
            [$body, $type] = $client->graph($query);
            return ['rdf' => $body, 'type' => $type, 'ms' => $client->lastMs];
        }
        $r = $client->select($query);
        $r['ms'] = $client->lastMs;
        return $r;
    }

    /**
     * Profilo di un gruppo: proprieta' usate da un campione di oggetti, piu' un secondo livello per le
     * proprieta' che portano ad altri oggetti (es. indirizzo -> via, civico; luogo -> sede -> indirizzo).
     * Il profilo e' un array percorso => elenco di proprieta', dove il percorso e' l'elenco degli IRI
     * separati da spazio ('' = proprieta' degli oggetti del gruppo).
     */
    public function profile(int $groupId, array $path = [], bool $auto = true): array
    {
        $g = $this->group($groupId) ?? throw new \InvalidArgumentException("Gruppo $groupId inesistente");
        if ($g['kind'] !== 'class') {
            throw new \InvalidArgumentException('Il profilo si calcola solo per i gruppi di tipo classe');
        }
        $e = $this->endpoint((int) $g['endpoint_id']);
        $client = $this->client($e);
        $b = $this->builder($e);
        $profile = $path ? $g['profile'] : [];

        $levels = [$path];
        $done = 0;
        while ($levels && $done < 12) {
            $p = array_shift($levels);
            $key = implode(' ', $p);
            $rows = $client->select($b->profile($g['graph_iri'], $g['item_iri'], $p))['rows'];
            $props = [];
            foreach ($rows as $r) {
                $props[] = [
                    'p'        => $r['p'],
                    'name'     => QueryBuilder::localName($r['p']),
                    'short'    => QueryBuilder::short($r['p']),
                    'n'        => (int) ($r['n'] ?? 0),
                    'subjects' => (int) ($r['soggetti'] ?? 0),
                    'example'  => mb_substr((string) ($r['esempio'] ?? ''), 0, 200),
                    'iri'      => (int) ($r['iri'] ?? 0) === 1,
                    'geo'      => self::geoKind($r['p']),
                ];
            }
            $profile[$key] = $props;
            $done++;
            // in automatico si esplorano fino a tre livelli, solo per le proprieta' che portano ad altri oggetti
            // e che hanno a che fare con luoghi (es. luogo -> sede -> indirizzo -> testo e civico)
            if ($auto && count($p) - count($path) < 2) {
                foreach ($props as $x) {
                    if ($x['iri'] && $x['p'] !== 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type'
                        && ($x['geo'] || preg_match('/site|sede|place|luogo|location|address|indirizzo|geometr|spatial|number|civico/i', $x['name']))) {
                        $levels[] = array_merge($p, [$x['p']]);
                    }
                }
                $levels = array_slice($levels, 0, 10);
            }
        }

        $geo = [];
        foreach ($profile as $k => $props) {
            foreach ($props as $x) {
                if ($x['geo']) {
                    $geo[$x['geo']] = true;
                }
            }
        }
        $count = null;
        try {
            $count = (int) ($client->select($b->count($g['graph_iri'], $g['item_iri']))['rows'][0]['n'] ?? 0);
        } catch (SparqlException) {
            // il conteggio e' facoltativo: su classi molto grandi puo' andare oltre il tempo massimo
        }
        $this->db->prepare("UPDATE gwc.sparql_groups SET profile = CAST(? AS jsonb), profiled_at = now(), geo = CAST(? AS text[]),
                                   n_instances = COALESCE(?, n_instances) WHERE id = ?")
                 ->execute([json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '{' . implode(',', array_keys($geo)) . '}',
                            $count, $groupId]);
        return $profile;
    }

    public static function geoKind(string $iri): ?string
    {
        $name = QueryBuilder::localName($iri);
        foreach (self::GEO_PATTERNS as $kind => $re) {
            if (preg_match($re, $name)) {
                return $kind;
            }
        }
        return null;
    }

    /**
     * Colonne proposte per lo scaricamento: etichette/nomi, dati geografici e i valori del secondo livello
     * che sono geografici (es. sede/indirizzo/testo completo).
     * @return array<int, array{name:string, path:string[]}>
     */
    public static function defaultColumns(array $profile): array
    {
        $cols = [];
        $textNames = '/^(label|title|name|preflabel|nome|denominazione|fulladdress|description|identifier)$/i';
        foreach ($profile[''] ?? [] as $x) {
            if (!$x['iri'] && ($x['geo'] || preg_match($textNames, $x['name']))) {
                $cols[] = ['name' => $x['name'], 'path' => [$x['p']]];
            }
        }
        foreach ($profile as $key => $props) {
            if ($key === '') {
                continue;
            }
            foreach ($props as $x) {
                if (!$x['iri'] && ($x['geo'] || preg_match($textNames, $x['name']))) {
                    $path = array_merge(explode(' ', $key), [$x['p']]);
                    $cols[] = ['name' => implode('_', array_map([QueryBuilder::class, 'localName'], $path)), 'path' => $path];
                }
            }
        }
        return self::uniqueNames($cols);
    }

    /** Nomi di colonna unici e adatti a CSV/GIS. */
    public static function uniqueNames(array $cols): array
    {
        $seen = [];
        foreach ($cols as &$c) {
            $n = trim((string) preg_replace('/[^A-Za-z0-9_]+/', '_', (string) $c['name']), '_') ?: 'col';
            $base = $n;
            for ($i = 2; isset($seen[strtolower($n)]); $i++) {
                $n = "{$base}_$i";
            }
            $seen[strtolower($n)] = true;
            $c['name'] = $n;
        }
        return $cols;
    }

    public function saveColumns(int $groupId, array $cols): void
    {
        $this->db->prepare("UPDATE gwc.sparql_groups SET columns = CAST(? AS jsonb) WHERE id = ?")
                 ->execute([json_encode(self::uniqueNames($cols), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $groupId]);
    }
}
