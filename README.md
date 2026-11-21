# GeoWebCrawler

Strumento in PHP, con interfaccia web su Apache, per **cercare, testare, analizzare e scaricare dati geografici** disponibili sul web: cataloghi CSW, servizi OGC (WMS, WFS, WCS…) e file scaricabili.

## Fasi del progetto

1. **Raccolta dei riferimenti**: da cataloghi CSW (ISO 19139, Dublin Core, GeoDCAT-AP), GetCapabilities dei servizi OGC, portali CKAN, cataloghi DCAT-AP/DCAT-AP_IT, cataloghi STAC e pagine web, con metadati standard e gestione dei duplicati. *(fatto)*
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

**Ripartire da zero tenendo le sorgenti**: `php bin\rebuild-db.php` salva le sorgenti in `storage\backup\sorgenti-<data>.json`, chiede conferma (scrivi `SI`), cancella tutto lo schema `gwc`, lo ricrea con le migrazioni e reimporta le sorgenti. Con `--export` salva soltanto le sorgenti. I file nella cartella `storage` restano.

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
| `csw` | Legge un catalogo CSW 2.0.2 con GetRecords, pagina per pagina. Prova i metadati ISO 19139 e, se il catalogo non li fornisce, Dublin Core. Con `"schema": "dcat"` chiede invece i record in GeoDCAT-AP (offerto per esempio da GeoNetwork 4). | `max_records` (25000), `page_size` (50), `schema` (`auto`, `iso`, `dc`, `dcat`), `constraint` (filtro CQL, es. `AnyText like '%idrografia%'`), `method` (`post` o `get`) |
| `ogc_service` | Usa il GetCapabilities di un servizio OGC come indice: una risorsa per il servizio e una per ogni layer (WMS), feature type (WFS), coverage (WCS) o layer WMTS, con titolo, descrizione, parole chiave, CRS ed estensione. Supporta WMS 1.1.1/1.3.0, WFS 1.0–2.0, WCS 1.0–2.0, WMTS. L'URL può essere quello di GeoServer `/ows`: vengono provati tutti i servizi. | `services` (es. `["WMS","WFS"]`; di default il SERVICE dell'URL o tutti), `max_records` (25000 layer per servizio) |
| `ckan` | Interroga l'API `package_search` di un portale CKAN (es. `https://www.dati.gov.it/opendata`) e importa i dataset con risorse geografiche: link, formati, licenza, organizzazione, campi DCAT-AP_IT, estensione dal campo `spatial`. | `q` (ricerca), `fq` (filtro Solr, es. `organization:regione-toscana`), `geo_only` (true), `max_records` (25000), `page_size` (100) |
| `dcat` | Legge un catalogo DCAT-AP / GeoDCAT-AP / DCAT-AP_IT in **RDF/XML**, seguendo la paginazione Hydra (es. `.../catalog.rdf?page=1` dei portali CKAN). Estrae titolo (preferendo l'italiano), descrizione, temi, date, frequenza, titolare/editore/contatti, estensione (WKT, GeoJSON o GML), CRS, licenze e distribuzioni (download e servizi). Turtle e JSON-LD non sono letti: serve la versione `.rdf`. | `max_records` (25000), `max_pages` (1000), `standard` (nome del profilo da registrare) |
| `stac` | Legge un catalogo STAC: STAC API (pagina iniziale con `/collections`) o catalogo statico (`catalog.json`). Ogni collezione diventa una risorsa (tipo "serie") con licenza, fornitori, estensione spaziale e temporale, EPSG e asset scaricabili; i singoli item non vengono importati. | `max_records` (25000), `max_depth` (4), `max_requests` (300) |
| `webpage` | Analizza una pagina web e trova i link a servizi OGC (WMS, WFS, WCS, WMTS, OGC API, ArcGIS REST) e a file geografici (zip, shp, gpkg, GeoJSON, GeoTIFF, KML…), anche se scritti come testo. | `depth` (0 = solo la pagina), `max_pages` (20), `include` (espressione regolare sugli URL da seguire), `same_host` (true) |

Ogni record trovato viene confrontato con l'archivio: le coppie sospette finiscono nella pagina **Duplicati**, dove scegli quale tenere o le segni come diverse (vedi [docs/database.md](docs/database.md)).

## Indicizzazione

- **Raccolta incrementale**: per i cataloghi CSW e i portali CKAN, "Raccogli" chiede solo i record modificati dall'ultima raccolta riuscita (filtro su `Modified` / `metadata_modified`). Ogni 30 giorni (parametro `full_every_days`) la raccolta è completa; si può anche forzarla con il pulsante **Raccolta completa** nella scheda della sorgente. Se un catalogo non accetta il filtro per data, l'incrementale viene disattivato da solo (`"incremental": false` nei parametri).
- **Record rimossi**: dopo una raccolta completa letta per intero, i record che la sorgente non contiene più sono segnati come rimossi; la risorsa diventa `removed` quando non è più in nessuna sorgente, e torna attiva se ricompare.
- **Servizi e dataset collegati**: un servizio che dichiara i dataset che pubblica (`srv:operatesOn`, `dcat:servesDataset`) o un dataset che ha un link a un layer di un servizio già in archivio vengono collegati. Nella scheda si vedono i servizi che mostrano il dataset, e viceversa; un servizio e un dataset collegati non sono proposti come duplicati.
- **Duplicati a gruppi**: nella pagina **Duplicati** le coppie collegate formano un gruppo; si sceglie la risorsa da tenere e si uniscono tutte le altre in un colpo. I gruppi con lo stesso identificativo si possono unire automaticamente.
- **Unione automatica con regole**: dalla pagina **Duplicati** → *Unione automatica con regole…* si scelgono le regole (stesso identificativo; stesso URL e titolo simile; titolo quasi uguale con stesso ente e stessa area; titolo identico e stessa area) e le soglie, si guarda l'anteprima e si applica. Una seconda regola scarta le coppie con titolo solo simile, enti diversi e aree lontane. Ogni unione si annulla dalla scheda della risorsa.
- **Duplicati in grafico**: ogni gruppo ha il pulsante *Grafo e mappa* (risorse come cerchi, coppie come linee colorate per motivo, aree sulla mappa; clic su un cerchio = tieni quella risorsa) e c'e' un grafo di tutti i gruppi della pagina. Nel confronto le celle diverse dalla risorsa tenuta sono evidenziate e si possono escludere dal gruppo le risorse che non c'entrano. Si filtrano i gruppi per parola, motivo e dimensione.
- **Ricerca**: nella pagina **Risorse** filtri a faccette (tipo, tema INSPIRE, ente, formato, accesso, licenza, sorgente, anno, stato) e ricerca nell'area visibile della mappa.
- **Enti**: nella pagina **Enti** le varianti dello stesso ente ("REGIONE PIEMONTE - Settore…", "Regione Piemonte (Direzione…)") si riuniscono sotto un ente principale; il programma suggerisce i gruppi con lo stesso nome di base.
- **Temi INSPIRE**: ogni risorsa riceve i temi INSPIRE riconosciuti dalle parole chiave (etichette e URI del registro INSPIRE, sinonimi italiani e inglesi), dalle categorie ISO o, in mancanza, dal titolo. Le regole sono nella tabella `gwc.theme_rules` e si possono ampliare.
- **Nuove sorgenti**: i servizi WMS/WFS/WCS/WMTS e i cataloghi CSW citati nei link delle risorse, e non ancora presenti, vengono proposti nella pagina **Sorgenti > Sorgenti proposte**, dove si approvano o si ignorano.

Le stesse operazioni da riga di comando:

```
php bin\index.php themes       ricalcola i temi INSPIRE (dopo aver cambiato gwc.theme_rules)
php bin\index.php links        ricalcola i collegamenti servizio-dataset
php bin\index.php discover     cerca nuove sorgenti
php bin\index.php duplicates   ricontrolla i duplicati in tutto l'archivio
php bin\index.php automerge    unisce i gruppi di duplicati con lo stesso identificativo
php bin\index.php rules        anteprima delle regole automatiche (--apply unisce, --reject scarta)
php bin\index.php orgs         riunisce gli enti suggeriti
```

**Raccolta automatica**: nell'*Utilità di pianificazione* di Windows crea un'attività giornaliera che esegue `C:\percorso\php.exe` con argomenti `bin\harvest.php --due` e cartella iniziale quella del progetto. Ogni sorgente viene raccolta con la frequenza impostata nella sua scheda (default 7 giorni).

**Se le richieste HTTPS falliscono** con "SSL certificate problem": scarica https://curl.se/ca/cacert.pem e indica il percorso in `config.php` alla voce `http.ca_bundle`.

## Test

```
php tests\parsers-test.php                        verifica i parser sui file di esempio (non serve il database)
php -S 127.0.0.1:8765 tests\mock-server.php       catalogo CSW e pagine web simulati, per provare la raccolta
```

Con il server simulato acceso si possono aggiungere come sorgenti:

| URL | Tipo |
|---|---|
| `http://127.0.0.1:8765/csw` | csw (anche con `{"schema": "dcat"}`) |
| `http://127.0.0.1:8765/page.html` | webpage, `{"depth": 1}` |
| `http://127.0.0.1:8765/ows` | ogc_service |
| `http://127.0.0.1:8765/ckan` | ckan |
| `http://127.0.0.1:8765/catalog.rdf?page=1` | dcat |
| `http://127.0.0.1:8765/stac` e `http://127.0.0.1:8765/stac-static/catalog.json` | stac |

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
