<?php
// Copia questo file in config.php e inserisci i tuoi dati.
// config.php non viene salvato su GitHub (vedi .gitignore).
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'dbname'   => 'geowebcrawler',
        'user'     => 'geowebcrawler',
        'password' => 'cambiami',
    ],
    // File con l'elenco delle sorgenti di partenza
    'sources_file' => __DIR__ . '/sources.json',
    // Cartella dove salvare i dati scaricati (fase 3)
    'download_dir' => __DIR__ . '/../storage/downloads',
    'http' => [
        'timeout'    => 30,
        'user_agent' => 'GeoWebCrawler/0.1 (+https://github.com/)',
    ],
];
