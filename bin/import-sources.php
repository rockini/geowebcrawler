<?php
declare(strict_types=1);
// Importa le sorgenti di partenza dal file di configurazione (config/sources.json).
// Le sorgenti gia' presenti (stesso URL normalizzato) vengono aggiornate, non duplicate.
// Uso:  php bin/import-sources.php [percorso/file.json]

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Config;
use GeoWebCrawler\Database;
use GeoWebCrawler\Repository\SourceRepository;

$file = $argv[1] ?? Config::get('sources_file');
if (!is_file($file)) {
    fwrite(STDERR, "File non trovato: $file\n");
    exit(1);
}
$data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$repo = new SourceRepository(Database::connection());

$new = $updated = 0;
foreach ($data['sources'] ?? [] as $i => $s) {
    if (empty($s['name']) || empty($s['type']) || empty($s['url'])) {
        fwrite(STDERR, "Sorgente #$i ignorata: servono name, type e url\n");
        continue;
    }
    [$id, $isNew] = $repo->upsert(
        $s['name'], $s['type'], $s['url'], $s['params'] ?? [],
        $s['enabled'] ?? true, $s['origin'] ?? 'config', $s['description'] ?? null
    );
    $isNew ? $new++ : $updated++;
    printf("%s  #%d  %s\n", $isNew ? 'nuova    ' : 'aggiorn. ', $id, $s['name']);
}
echo "Sorgenti nuove: $new, aggiornate: $updated\n";
