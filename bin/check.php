<?php
declare(strict_types=1);
// Verifica della disponibilita' di servizi e link (fase 2).
//
//   php bin/check.php [filtri] [--limit=N] [-v]       crea e avvia una verifica
//   php bin/check.php --batch=ID                      esegue (o riprende) una verifica creata dall'interfaccia
//   php bin/check.php --count [filtri]                dice solo quanti servizi/indirizzi verrebbero provati
//
// Filtri (come nella pagina "Verifiche"):
//   --type=wms,wfs        tipi di accesso (wms, wfs, wcs, wmts, csw, sos, download, webpage, atom, arcgis_rest...)
//   --status=never        never (mai verificati) | error | warn | ok | problems (avvisi o errori) | any
//   --older=30            verificati l'ultima volta da piu' di 30 giorni (o mai)
//   --source=3  --org=12  --theme=hy  --q=parola  --host=regione.lazio  --resource=123
// Opzioni:
//   --limit=N             al massimo N servizi/indirizzi (default 1000)
//   --concurrency=8       richieste contemporanee (default da config: check.concurrency)
//   -v                    una riga per ogni verifica
//
// Esempio per l'Utilita' di pianificazione (ogni notte): bin\check.php --older=30 --limit=5000

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Check\CheckRunner;
use GeoWebCrawler\Check\CheckSelection;
use GeoWebCrawler\Database;

set_time_limit(0);
$db = Database::connection();
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    } elseif ($a === '-v') {
        $opt['v'] = true;
    } else {
        fwrite(STDERR, "Argomento sconosciuto: $a (vedi l'inizio di bin/check.php)\n");
        exit(1);
    }
}
$runner = new CheckRunner($db);
$verbose = !empty($opt['v']);
$runner->onProgress(fn(string $l) => $verbose || !preg_match('/^\d\d:\d\d:\d\d\s+\d+ /', $l) ? print($l . PHP_EOL) : null);
$checkerOpts = isset($opt['concurrency']) ? ['concurrency' => (int) $opt['concurrency']] : [];

try {
    if (isset($opt['batch'])) {
        $b = $runner->run((int) $opt['batch'], null, $checkerOpts);
    } else {
        $filter = array_intersect_key($opt, array_flip(CheckSelection::FILTERS));
        if (isset($opt['count'])) {
            $c = (new CheckSelection($db, $filter))->count();
            echo "Servizi o indirizzi: {$c['targets']} (punti di accesso: {$c['endpoints']}, risorse: {$c['resources']})\n";
            exit(0);
        }
        $id = $runner->createBatch($filter, (int) ($opt['limit'] ?? 1000), 'cli');
        $b = $runner->run($id, null, $checkerOpts);
    }
    exit($b['status'] === 'error' ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
