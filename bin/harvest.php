<?php
declare(strict_types=1);
// Raccoglie i riferimenti ai dati dalle sorgenti.
// Uso:
//   php bin/harvest.php 3          raccoglie dalla sorgente con id 3
//   php bin/harvest.php --due      tutte le sorgenti attive la cui raccolta e' scaduta (harvest_every)
//   php bin/harvest.php --all      tutte le sorgenti attive
// Opzioni (insieme a quanto sopra):
//   -v               mostra la progressione ogni 100 record (numero letti, nuovi, aggiornati, errori, velocita')
//   -v=500           ogni 500 record
//   -vv              una riga per ogni record (titolo e se e' nuovo o aggiornato)
//   --completa       raccolta completa anche se sarebbe possibile quella incrementale
// Con --due si puo' pianificare l'esecuzione con l'Utilita' di pianificazione di Windows.

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;
use GeoWebCrawler\Harvest\HarvestRunner;

set_time_limit(0);
$db = Database::connection();
$opts = array_values(array_filter(array_slice($argv, 1), fn($a) => str_starts_with($a, '-') && !in_array($a, ['--all', '--due'], true)));
$arg = array_values(array_filter(array_slice($argv, 1), fn($a) => !in_array($a, $opts, true)))[0] ?? '';
$verbosity = 0;
$every = 100;
$full = false;
foreach ($opts as $o) {
    if ($o === '-vv') {
        $verbosity = 2;
    } elseif (preg_match('/^-v(?:=(\d+))?$/', $o, $m)) {
        $verbosity = max($verbosity, 1);
        $every = isset($m[1]) ? max(1, (int) $m[1]) : $every;
    } elseif ($o === '--completa') {
        $full = true;
    } else {
        fwrite(STDERR, "Opzione sconosciuta: $o\n");
        exit(1);
    }
}

if (ctype_digit($arg)) {
    $ids = [(int) $arg];
} elseif ($arg === '--all' || $arg === '--due') {
    $types = "'" . implode("','", HarvestRunner::SUPPORTED) . "'";
    $sql = "SELECT id FROM gwc.sources WHERE enabled AND source_type IN ($types)";
    if ($arg === '--due') {
        $sql .= " AND (last_harvest_at IS NULL OR last_harvest_at + harvest_every <= now())";
    }
    $ids = $db->query($sql . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
} else {
    fwrite(STDERR, "Uso: php bin/harvest.php <id sorgente> | --all | --due  [-v | -v=N | -vv] [--completa]\n");
    exit(1);
}

$runner = new HarvestRunner($db);
$runner->verbosity = $verbosity;
$runner->progressEvery = $every;
$exit = 0;
foreach ($ids as $id) {
    $run = $runner->run((int) $id, fn(string $line) => print($line . PHP_EOL), $full);
    if ($run['status'] === 'error') {
        $exit = 2;
    }
    echo str_repeat('-', 60), PHP_EOL;
}
if (!$ids) {
    echo "Nessuna sorgente da raccogliere.\n";
}
exit($exit);
