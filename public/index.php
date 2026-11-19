<?php
declare(strict_types=1);
// Punto di ingresso dell'interfaccia web: Apache deve servire solo la cartella public/.

require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Database;
use GeoWebCrawler\Web\App;

try {
    $db = Database::connection();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Impossibile collegarsi al database.\n\n" . $e->getMessage()
       . "\n\nControlla i dati in config/config.php.";
    exit;
}
(new App($db))->handle();
