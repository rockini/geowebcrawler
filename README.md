# GeoWebCrawler

Strumento in PHP, con interfaccia web su Apache, per **cercare, testare, analizzare e scaricare dati geografici** disponibili sul web: cataloghi CSW, servizi OGC (WMS, WFS, WCS…) e file scaricabili.

## Fasi del progetto

1. **Raccolta dei riferimenti**: da cataloghi CSW e da pagine web, con metadati standard e gestione dei duplicati. *(in corso)*
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

## Struttura

```
bin/       script da riga di comando (migrazioni, import, poi raccolta e test)
config/    configurazione (i file con le tue password non vanno su GitHub)
docs/      documentazione (struttura del database: docs/database.md)
public/    unica cartella esposta da Apache
sql/       script di creazione e aggiornamento del database, in ordine
src/       codice PHP (namespace GeoWebCrawler)
storage/   dati scaricati e log
```
