<?php
declare(strict_types=1);

// Caricamento automatico delle classi: usa Composer se presente,
// altrimenti un semplice autoloader PSR-4 (il progetto funziona anche senza Composer).
$composer = __DIR__ . '/../vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'GeoWebCrawler\\';
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

if (defined('GWC_SKIP_CONFIG')) {
    return [];   // usato dai test che non toccano il database
}
$config = GeoWebCrawler\Config::load(__DIR__ . '/../config/config.php');
date_default_timezone_set($config['timezone'] ?? 'Europe/Rome');
return $config;
