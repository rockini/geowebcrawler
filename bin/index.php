<?php
declare(strict_types=1);
// Operazioni di manutenzione dell'indice (le stesse disponibili nell'interfaccia).
// Uso:
//   php bin/index.php themes        ricalcola i temi INSPIRE di tutte le risorse (dopo aver cambiato gwc.theme_rules)
//   php bin/index.php links         ricalcola i collegamenti servizio-dataset
//   php bin/index.php discover      cerca nuove sorgenti nei link delle risorse
//   php bin/index.php duplicates    ricontrolla i duplicati in tutto l'archivio
//   php bin/index.php automerge     unisce i gruppi di duplicati con lo stesso identificativo
//   php bin/index.php rules [--apply] [--reject]
//                                   regole automatiche sui duplicati (vedi pagina "Unione automatica"):
//                                   senza opzioni mostra solo l'anteprima
//   php bin/index.php orgs          riunisce gli enti suggeriti (stesso nome di base)

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;
use GeoWebCrawler\Repository\DuplicateGroups;
use GeoWebCrawler\Repository\DuplicateRules;
use GeoWebCrawler\Repository\Organizations;
use GeoWebCrawler\Repository\SourceDiscovery;

set_time_limit(0);
$db = Database::connection();
$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'themes':
        echo 'Risorse con temi aggiornati: ', $db->query("SELECT gwc.assign_themes()")->fetchColumn(), PHP_EOL;
        break;
    case 'links':
        echo 'Nuovi collegamenti: ', $db->query("SELECT gwc.resolve_links()")->fetchColumn(), PHP_EOL;
        break;
    case 'discover':
        echo 'Nuove sorgenti proposte: ', (new SourceDiscovery($db))->discover(), PHP_EOL;
        break;
    case 'duplicates':
        echo 'Nuove coppie da verificare: ', $db->query("SELECT gwc.detect_duplicates()")->fetchColumn(), PHP_EOL;
        break;
    case 'automerge':
        [$g, $n] = (new DuplicateGroups($db))->autoMerge();
        echo "Gruppi uniti: $g, risorse unite: $n", PHP_EOL;
        break;
    case 'rules':
        $rules = new DuplicateRules($db);
        $p = $rules->preview(0);
        echo "Anteprima: {$p['groups']} gruppi da unire ({$p['resources']} risorse), {$p['skipped']} gruppi troppo grandi saltati, ",
             "{$p['reject']} coppie da scartare", PHP_EOL;
        if (in_array('--apply', $argv, true)) {
            [$g, $n] = $rules->applyMerge();
            echo "Uniti $g gruppi, $n risorse", PHP_EOL;
        }
        if (in_array('--reject', $argv, true)) {
            echo 'Coppie scartate: ', $rules->applyReject(), PHP_EOL;
        }
        break;
    case 'orgs':
        echo 'Enti riuniti: ', (new Organizations($db))->autoMerge(), PHP_EOL;
        break;
    default:
        fwrite(STDERR, "Uso: php bin/index.php themes|links|discover|duplicates|automerge|rules|orgs\n");
        exit(1);
}
