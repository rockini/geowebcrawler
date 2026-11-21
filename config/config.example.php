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
    // Cartella dei file esterni (estrazioni SPARQL e altri dati grandi): nel database restano solo i
    // riferimenti (tabella gwc.files, percorsi relativi a questa cartella). Puo' stare anche su un altro disco.
    'storage_dir' => __DIR__ . '/../storage',
    // Separatore dei file CSV prodotti (',' standard; ';' se li apri con Excel in italiano)
    'csv_separator' => ',',
    // Fuso orario per date e orari mostrati nell'interfaccia
    'timezone' => 'Europe/Rome',
    // Numero massimo di record letti da una sorgente in una raccolta, se la sorgente
    // non indica un proprio "max_records" nei parametri
    'harvest' => [
        'max_records' => 25000,
    ],
    'http' => [
        'timeout'    => 30,
        // Non usare parole come "crawler", "bot" o "spider": GeoNetwork tratta questi client
        // come motori di ricerca e risponde alle richieste CSW con NullPointerException.
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GWC/0.1',
        // Su Windows, se le richieste HTTPS falliscono con "SSL certificate problem",
        // scarica https://curl.se/ca/cacert.pem e indica qui il percorso del file:
        'ca_bundle'  => null,   // es. 'C:/php/extras/ssl/cacert.pem'
    ],
];
