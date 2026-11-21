<?php
declare(strict_types=1);
// Strumento SPARQL da riga di comando (le stesse funzioni della pagina "SPARQL" dell'interfaccia).
//
//   php bin/sparql.php endpoints                                  elenca gli endpoint
//   php bin/sparql.php search <endpoint> "parole chiave" [--mode=text|classes|datasets] [--geo] [--show]
//                                                                 cerca i gruppi di dati e li salva (--show: mostra solo la query)
//   php bin/sparql.php groups [<endpoint>] [parola]               elenca i gruppi trovati
//   php bin/sparql.php profile <gruppo>                           calcola le proprieta' usate dal gruppo
//   php bin/sparql.php download <gruppo> [--format=csv|rdf] [--max=N]
//                                                                 scarica il gruppo in un file (cartella storage/sparql)
//   php bin/sparql.php download-selected [--format=csv|rdf]       scarica tutti i gruppi selezionati
//   php bin/sparql.php query <endpoint> <file.rq>                 esegue una query scritta in un file

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;
use GeoWebCrawler\Sparql\Downloader;
use GeoWebCrawler\Sparql\SparqlService;
use GeoWebCrawler\Storage\FileStore;

$db = Database::connection();
$svc = new SparqlService($db);
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$opt = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}
$cmd = $args[0] ?? '';
$echo = fn(string $l) => print($l . PHP_EOL);

try {
    switch ($cmd) {
        case 'endpoints':
            foreach ($db->query("SELECT id, name, url FROM gwc.sparql_endpoints ORDER BY id") as $e) {
                echo "{$e['id']}\t{$e['name']}\t{$e['url']}\n";
            }
            break;

        case 'search':
            [$ep, $kw] = [(int) ($args[1] ?? 0), (string) ($args[2] ?? '')];
            $mode = (string) ($opt['mode'] ?? 'text');
            if (!empty($opt['show'])) {
                echo $svc->searchQuery($svc->endpoint($ep), $kw, $mode, !empty($opt['geo'])), PHP_EOL;
                break;
            }
            $r = $svc->search($ep, $kw, $mode, !empty($opt['geo']));
            echo "Risultati: {$r['rows']}, gruppi nuovi: {$r['new']}\n";
            foreach ($r['groups'] as $id) {
                $g = $svc->group($id);
                echo "  #$id\t" . ($g['n_instances'] ?? '?') . "\t" . ($g['label'] ?: $g['item_iri']) . ($g['graph_iri'] ? "\t[{$g['graph_iri']}]" : '') . "\n";
            }
            break;

        case 'groups':
            $st = $db->prepare("SELECT id, kind, n_instances, coalesce(label, item_iri) AS l, array_to_string(geo, ',') AS geo
                                  FROM gwc.sparql_groups WHERE (? = 0 OR endpoint_id = ?) AND (? = '' OR ? = ANY (keywords) OR label ILIKE ?)
                                 ORDER BY n_instances DESC NULLS LAST LIMIT 500");
            $ep = (int) ($args[1] ?? 0);
            $w = (string) ($args[2] ?? '');
            $st->execute([$ep, $ep, $w, mb_strtolower($w), "%$w%"]);
            foreach ($st as $g) {
                echo "#{$g['id']}\t{$g['kind']}\t" . ($g['n_instances'] ?? '?') . "\t{$g['l']}" . ($g['geo'] ? "\t(geo: {$g['geo']})" : '') . "\n";
            }
            break;

        case 'profile':
            $p = $svc->profile((int) ($args[1] ?? 0));
            foreach ($p as $path => $props) {
                echo ($path === '' ? 'Proprieta\' degli oggetti' : "Seguendo $path"), ":\n";
                foreach ($props as $x) {
                    echo "  {$x['subjects']}\t{$x['short']}" . ($x['geo'] ? "  [{$x['geo']}]" : '') . ($x['iri'] ? '  ->' : '') . "\t" . mb_substr($x['example'], 0, 60) . "\n";
                }
            }
            break;

        case 'download':
        case 'download-selected':
            $ids = $cmd === 'download' ? [(int) ($args[1] ?? 0)]
                 : $db->query("SELECT id FROM gwc.sparql_groups WHERE selected ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            $dl = (new Downloader($db, $svc, new FileStore($db)))->onProgress($echo);
            foreach ($ids as $id) {
                $r = $dl->download((int) $id, (string) ($opt['format'] ?? 'csv'), null, isset($opt['max']) ? (int) $opt['max'] : null);
                echo "Gruppo #$id: {$r['status']}, {$r['n_subjects']} oggetti" . ($r['error'] ? " - {$r['error']}" : '') . "\n";
                foreach (['file_id', 'geojson_file_id'] as $k) {
                    if ($r[$k]) {
                        $f = $db->query("SELECT path FROM gwc.files WHERE id = " . (int) $r[$k])->fetchColumn();
                        echo "  file: storage/$f\n";
                    }
                }
            }
            break;

        case 'query':
            $q = (string) @file_get_contents((string) ($args[2] ?? ''));
            if ($q === '') {
                throw new InvalidArgumentException('File della query mancante o vuoto');
            }
            $r = $svc->run((int) ($args[1] ?? 0), $q);
            if (isset($r['rdf'])) {
                echo $r['rdf'];
                break;
            }
            echo implode("\t", $r['vars']), PHP_EOL;
            foreach ($r['rows'] as $row) {
                echo implode("\t", array_map(fn($v) => $row[$v] ?? '', $r['vars'])), PHP_EOL;
            }
            fwrite(STDERR, count($r['rows']) . " righe, {$r['ms']} ms\n");
            break;

        default:
            fwrite(STDERR, "Uso: php bin/sparql.php endpoints|search|groups|profile|download|download-selected|query (vedi l'inizio del file)\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
