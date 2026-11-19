<?php
declare(strict_types=1);
// Crea o aggiorna il database eseguendo, in ordine, gli script sql/NNN_*.sql non ancora applicati.
// Uso:  php bin/migrate.php

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;

$db = Database::connection();
$db->exec("CREATE TABLE IF NOT EXISTS public.gwc_migrations (
              name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())");
$done = $db->query("SELECT name FROM public.gwc_migrations")->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/../sql/[0-9][0-9][0-9]_*.sql');
sort($files);
$applied = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    echo "Applico $name ... ";
    $db->beginTransaction();
    try {
        $db->exec(file_get_contents($file));
        $db->prepare("INSERT INTO public.gwc_migrations (name) VALUES (?)")->execute([$name]);
        $db->commit();
        echo "ok\n";
        $applied++;
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "ERRORE\n" . $e->getMessage() . "\n");
        exit(1);
    }
}
echo $applied ? "Migrazioni applicate: $applied\n" : "Database gia' aggiornato.\n";
