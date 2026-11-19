<?php
declare(strict_types=1);
// Raccoglie i riferimenti ai dati dalle sorgenti.
// Uso:
//   php bin/harvest.php 3          raccoglie dalla sorgente con id 3
//   php bin/harvest.php --due      tutte le sorgenti attive la cui raccolta e' scaduta (harvest_every)
//   php bin/harvest.php --all      tutte le sorgenti attive
// Con --due si puo' pianificare l'esecuzione con l'Utilita' di pianificazione di Windows.

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;
use GeoWebCrawler\Harvest\HarvestRunner;

set_time_limit(0);
$db = Database::connection();
$arg = $argv[1] ?? '';

if (ctype_digit($arg)) {
    $ids = [(int) $arg];
} elseif ($arg === '--all' || $arg === '--due') {
    $sql = "SELECT id FROM gwc.sources WHERE enabled AND source_type IN ('csw','webpage')";
    if ($arg === '--due') {
        $sql .= " AND (last_harvest_at IS NULL OR last_harvest_at + harvest_every <= now())";
    }
    $ids = $db->query($sql . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
} else {
    fwrite(STDERR, "Uso: php bin/harvest.php <id sorgente> | --all | --due\n");
    exit(1);
}

$runner = new HarvestRunner($db);
$exit = 0;
foreach ($ids as $id) {
    $run = $runner->run((int) $id, fn(string $line) => print($line . PHP_EOL));
    if ($run['status'] === 'error') {
        $exit = 2;
    }
    echo str_repeat('-', 60), PHP_EOL;
}
if (!$ids) {
    echo "Nessuna sorgente da raccogliere.\n";
}
exit($exit);
