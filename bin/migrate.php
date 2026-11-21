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

// Database creato eseguendo a mano gli script SQL: si riconoscono quelli gia' applicati
// controllando un oggetto creato da ciascuno, e si registrano senza rieseguirli.
$markers = [
    '001_schema.sql'               => "SELECT to_regclass('gwc.resources') IS NOT NULL",
    '002_duplicates.sql'           => "SELECT to_regclass('gwc.v_duplicates_pending') IS NOT NULL",
    '003_seed.sql'                 => "SELECT to_regclass('gwc.formats') IS NOT NULL AND EXISTS (SELECT 1 FROM gwc.formats)",
    '004_harvest.sql'              => "SELECT EXISTS (SELECT 1 FROM information_schema.columns
                                        WHERE table_schema = 'gwc' AND table_name = 'harvest_runs' AND column_name = 'log')",
    '005_indici.sql'               => "SELECT to_regclass('gwc.source_types') IS NOT NULL
                                        AND EXISTS (SELECT 1 FROM gwc.source_types WHERE code = 'stac')",
    '006_duplicati_meno_falsi.sql' => "SELECT to_regprocedure('gwc.title_numbers(text)') IS NOT NULL",
    '006_duplicati_veloce.sql'     => "SELECT EXISTS (SELECT 1 FROM information_schema.columns
                                        WHERE table_schema = 'gwc' AND table_name = 'resources' AND column_name = 'title_nums')",
    '007_indicizzazione.sql'       => "SELECT to_regclass('gwc.themes') IS NOT NULL",
];
foreach ($markers as $name => $check) {
    if (in_array($name, $done, true) || !is_file(__DIR__ . "/../sql/$name")) {
        continue;
    }
    try {
        $present = (bool) $db->query($check)->fetchColumn();
    } catch (PDOException) {
        $present = false;    // database nuovo: le tabelle controllate non esistono ancora
    }
    if ($present) {
        $db->prepare("INSERT INTO public.gwc_migrations (name) VALUES (?)")->execute([$name]);
        $done[] = $name;
        echo "Gia' presente nel database: $name\n";
    }
}

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
