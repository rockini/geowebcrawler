<?php
declare(strict_types=1);
// Ricrea da zero la struttura del database tenendo solo le sorgenti.
//
//   php bin/rebuild-db.php            chiede conferma, poi:
//     1. salva le sorgenti in storage/backup/sorgenti-AAAAMMGG-HHMMSS.json
//        (non quelle proposte dalla scoperta automatica e non ancora accettate)
//     2. cancella lo schema gwc (risorse, duplicati, enti, raccolte, gruppi SPARQL...: tutto)
//     3. riapplica tutti gli script sql/ (come php bin/migrate.php)
//     4. reimporta le sorgenti salvate
//   php bin/rebuild-db.php --export   esegue solo il punto 1
//   php bin/rebuild-db.php --si       non chiede conferma
//
// I file nella cartella storage (scaricamenti, estrazioni SPARQL) non vengono cancellati.
// Le sorgenti salvate si possono reimportare anche a mano: php bin/import-sources.php <file.json>

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;
use GeoWebCrawler\Repository\SourceRepository;

$db = Database::connection();

// 1. salvataggio delle sorgenti
$sources = [];
if ($db->query("SELECT to_regclass('gwc.sources') IS NOT NULL")->fetchColumn()) {
    foreach ($db->query("SELECT * FROM gwc.sources ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (($s['origin'] ?? '') === 'discovered' && ($s['discovery_status'] ?? null) !== 'accepted') {
            continue;   // proposte automatiche non accettate: si ritrovano alla prossima raccolta
        }
        $sources[] = array_filter([
            'name'          => $s['name'],
            'type'          => $s['source_type'],
            'url'           => $s['url'],
            'description'   => $s['description'] ?? null,
            'enabled'       => in_array($s['enabled'], [true, 't', 1, '1'], true),
            'origin'        => $s['origin'] ?? 'ui',
            'harvest_every' => $s['harvest_every'] ?? null,
            'params'        => (object) (json_decode((string) ($s['params'] ?? '{}'), true) ?: []),
        ], fn($v) => $v !== null);
    }
}
$dir = __DIR__ . '/../storage/backup';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$file = realpath($dir) . DIRECTORY_SEPARATOR . 'sorgenti-' . date('Ymd-His') . '.json';
file_put_contents($file, json_encode(['sources' => $sources], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo count($sources) . " sorgenti salvate in $file\n";
if (in_array('--export', $argv, true)) {
    exit(0);
}

// 2. conferma e cancellazione
if (!in_array('--si', $argv, true)) {
    echo "\nATTENZIONE: verranno cancellati TUTTI i dati del database tranne le sorgenti appena salvate.\n";
    echo "Scrivi SI per continuare: ";
    if (trim((string) fgets(STDIN)) !== 'SI') {
        echo "Annullato. Il database non e' stato toccato.\n";
        exit(1);
    }
}
$db->exec("DROP SCHEMA IF EXISTS gwc CASCADE");
$db->exec("DROP TABLE IF EXISTS public.gwc_migrations");
echo "Schema gwc cancellato.\n";

// 3. struttura
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/migrate.php'), $code);
if ($code !== 0) {
    fwrite(STDERR, "Migrazioni non riuscite. Le sorgenti sono salvate in $file: dopo aver risolto,\n"
                 . "esegui php bin/migrate.php e poi php bin/import-sources.php \"$file\"\n");
    exit(1);
}

// 4. sorgenti
$repo = new SourceRepository($db);
$n = 0;
foreach ($sources as $s) {
    [$id] = $repo->upsert($s['name'], $s['type'], $s['url'], (array) $s['params'], $s['enabled'],
                          $s['origin'] === 'discovered' ? 'discovered' : $s['origin'], $s['description'] ?? null);
    if (isset($s['harvest_every'])) {
        $db->prepare("UPDATE gwc.sources SET harvest_every = CAST(? AS interval) WHERE id = ?")->execute([$s['harvest_every'], $id]);
    }
    $n++;
}
echo "Sorgenti reimportate: $n. Database pronto.\n";
