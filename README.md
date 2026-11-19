# GeoWebCrawler

Strumento in PHP, con interfaccia web su Apache, per **cercare, testare, analizzare e scaricare dati geografici** disponibili sul web: cataloghi CSW, servizi OGC (WMS, WFS, WCS…) e file scaricabili.

## Fasi del progetto

1. **Raccolta dei riferimenti**: da cataloghi CSW e da pagine web, con metadati standard e gestione dei duplicati. *(fatto)*
2. **Test di disponibilità**: verifica che link e servizi rispondano davvero.
3. **Download**: scaricamento dei dati in un archivio locale, con possibile import in PostGIS.

Versione estesa futura: riferimenti da feed di riviste, blog e social.

## Requisiti

- PHP 8.2 o superiore (sviluppato con 8.5) con le estensioni `pdo_pgsql`, `curl`, `dom`, `mbstring`
- PostgreSQL 16 o superiore con **PostGIS 3**
- Apache (o il server integrato di PHP per le prove)
- Composer è facoltativo: senza, il progetto usa un suo caricatore di classi

## Installazione su Windows

1. **PostGIS**: se non c'è già, installalo con *Application Stack Builder* (si apre dal menu di PostgreSQL) → *Spatial Extensions* → PostGIS.
2. **Estensioni PHP**: in `php.ini` togli il `;` davanti a `extension=pdo_pgsql`, `extension=curl`, `extension=mbstring`, poi riavvia Apache.
3. **Database**: da *SQL Shell (psql)* o pgAdmin, come utente `postgres`:
   ```sql
   CREATE ROLE geowebcrawler LOGIN PASSWORD 'una-password';
   CREATE DATABASE geowebcrawler OWNER geowebcrawler;
   \c geowebcrawler
   CREATE EXTENSION postgis;
   CREATE EXTENSION pg_trgm;
   CREATE EXTENSION unaccent;
   ```
   Le estensioni vanno create da `postgres` perché l'utente del progetto non ha i permessi per farlo.
4. **Configurazione**: copia `config\config.example.php` in `config\config.php` e inserisci la password. Copia `config\sources.example.json` in `config\sources.json` e scrivi le tue sorgenti di partenza.
5. **Crea le tabelle e importa le sorgenti** dalla cartella del progetto:
   ```
   php bin\migrate.php
   php bin\import-sources.php
   ```
6. **Apri l'interfaccia**: per una prova veloce `php -S 127.0.0.1:8080 -t public` e poi http://127.0.0.1:8080. Con Apache, fai puntare un VirtualHost (o un `Alias`) alla cartella `public`.

## Aggiornare un'installazione esistente

Dopo aver scaricato una nuova versione (`git pull`), lancia di nuovo `php bin\migrate.php`: applica solo le modifiche al database che mancano.

## Raccogliere i riferimenti ai dati

Dall'interfaccia: pagina **Sorgenti**, pulsante **Raccogli**. Da riga di comando:

```
php bin\harvest.php 3        raccoglie dalla sorgente con id 3
php bin\harvest.php --due    tutte le sorgenti attive la cui raccolta e' scaduta
php bin\harvest.php --all    tutte le sorgenti attive
```

Tipi di sorgente supportati. Il limite di record per raccolta è 25000 per tutti i tipi: si cambia in `config.php` (`harvest.max_records`) oppure per la singola sorgente con il parametro `max_records`.

| Tipo | Cosa fa | Parametri (campo JSON) |
|---|---|---|
| `csw` | Legge un catalogo CSW 2.0.2 con GetRecords, pagina per pagina. Prova i metadati ISO 19139 e, se il catalogo non li fornisce, Dublin Core. | `max_records` (25000), `page_size` (50), `schema` (`auto`, `iso`, `dc`), `constraint` (filtro CQL, es. `AnyText like '%idrografia%'`), `method` (`post` o `get`) |
| `webpage` | Analizza una pagina web e trova i link a servizi OGC (WMS, WFS, WCS, WMTS, OGC API, ArcGIS REST) e a file geografici (zip, shp, gpkg, GeoJSON, GeoTIFF, KML…), anche se scritti come testo. | `depth` (0 = solo la pagina), `max_pages` (20), `include` (espressione regolare sugli URL da seguire), `same_host` (true) |

Ogni record trovato viene confrontato con l'archivio: le coppie sospette finiscono nella pagina **Duplicati**, dove scegli quale tenere o le segni come diverse (vedi [docs/database.md](docs/database.md)).

**Raccolta automatica**: nell'*Utilità di pianificazione* di Windows crea un'attività giornaliera che esegue `C:\percorso\php.exe` con argomenti `bin\harvest.php --due` e cartella iniziale quella del progetto. Ogni sorgente viene raccolta con la frequenza impostata nella sua scheda (default 7 giorni).

**Se le richieste HTTPS falliscono** con "SSL certificate problem": scarica https://curl.se/ca/cacert.pem e indica il percorso in `config.php` alla voce `http.ca_bundle`.

## Test

```
php tests\parsers-test.php                        verifica i parser sui file di esempio (non serve il database)
php -S 127.0.0.1:8765 tests\mock-server.php       catalogo CSW e pagine web simulati, per provare la raccolta
```

Con il server simulato acceso si possono aggiungere come sorgenti `http://127.0.0.1:8765/csw` (tipo csw) e `http://127.0.0.1:8765/page.html` (tipo webpage, parametri `{"depth": 1}`).

## Struttura

```
bin/       script da riga di comando (migrazioni, import sorgenti, raccolta)
config/    configurazione (i file con le tue password non vanno su GitHub)
docs/      documentazione (struttura del database: docs/database.md)
public/    unica cartella esposta da Apache
sql/       script di creazione e aggiornamento del database, in ordine
src/       codice PHP (namespace GeoWebCrawler)
  Harvest/     lettura di CSW, pagine web, metadati ISO 19139 e Dublin Core
  Http/        client HTTP (cURL)
  Repository/  salvataggio nel database
  Web/         interfaccia web
templates/ pagine dell'interfaccia
storage/   dati scaricati e log
tests/     test e server simulato
```
