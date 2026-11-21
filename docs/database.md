# Struttura del database

Tutte le tabelle stanno nello schema `gwc` di PostgreSQL. Servono le estensioni **PostGIS** (geometrie), **pg_trgm** (somiglianza tra testi) e **unaccent** (confronto senza accenti).

## Schema in breve

```
sources ──< harvest_runs
   │
   └──< resource_sources >── resources ──< endpoints ──< endpoint_checks   (fase 2)
                               │  │                 └──< downloads         (fase 3)
          organizations ──< resource_parties
                               │
                     duplicate_candidates (coppie di resources)
```

`A ──< B` significa "un A ha molti B".

## Tabelle principali

| Tabella | A cosa serve |
|---|---|
| `sources` | Le sorgenti da cui si raccolgono i riferimenti: cataloghi CSW, pagine web, portali CKAN e, nella versione estesa, feed, blog e social. Si caricano dal file `config/sources.json` (`origin = 'config'`) o dall'interfaccia (`'ui'`). Il campo `params` (JSON) contiene le opzioni del singolo tipo, per esempio filtri CSW o profondità di navigazione. |
| `harvest_runs` | Un record per ogni esecuzione della raccolta su una sorgente: esito, quanti record trovati, nuovi e aggiornati, e il log completo. |
| `resources` | I dataset e i servizi trovati, con i metadati standard: titolo, descrizione, parole chiave, date (creazione, pubblicazione, revisione), licenza, vincoli d'accesso, sistemi di riferimento (EPSG), estensione geografica (`bbox` in WGS84), standard dei metadati e metadati originali (`raw_metadata`). |
| `organizations` / `resource_parties` | Enti e autori, e il loro ruolo rispetto alla risorsa (autore, editore, contatto, distributore…). Lo stesso ente scritto in modi diversi (maiuscole, accenti) viene riconosciuto come uno solo. |
| `resource_sources` | In quali sorgenti è stata vista ogni risorsa, e quando la prima e l'ultima volta. Una risorsa presente in più cataloghi resta **una sola risorsa** con più provenienze. |
| `endpoints` | I punti di accesso concreti: URL di download, WMS, WFS, WCS, WMTS, OGC API, ArcGIS REST, Atom INSPIRE… con formato, versione del servizio e nome del layer. |
| `endpoint_checks` | *(fase 2)* Storico dei test di disponibilità: codice HTTP, tempo di risposta, tipo di contenuto, validità del GetCapabilities. L'ultimo esito è copiato anche in `endpoints.last_check_ok`. |
| `downloads` | *(fase 3)* Scaricamenti eseguiti: file locale, dimensione, impronta SHA-256, eventuale tabella PostGIS in cui il dato è stato importato. |
| `duplicate_candidates` | Coppie di risorse che sembrano duplicate, in attesa che tu le confermi o le scarti dall'interfaccia. |
| `resource_links` | Collegamenti servizio → dataset pubblicato: dichiarati nei metadati (`origin = 'metadata'`, da `srv:operatesOn` o `dcat:servesDataset`, i cui riferimenti grezzi stanno in `resource_link_refs`) o ricavati dai link (`'endpoint'`: il dataset ha un link a un layer dello stesso servizio, confrontato con `endpoints.service_key`). Si ricalcolano con `gwc.resolve_links()`. |
| `themes` / `theme_rules` | I 34 temi INSPIRE e le regole (espressioni regolari su parole chiave, categorie ISO e, in mancanza, titolo) che li assegnano a `resources.themes`. Dopo aver cambiato le regole: `SELECT gwc.assign_themes();`. |

Tabelle di vocabolario, ampliabili dall'interfaccia: `source_types`, `endpoint_types`, `formats` (con le estensioni dei file riconosciute) e `licenses` (codici SPDX dove esistono, più le varianti testuali usate per riconoscerle, per esempio "IODL 2.0").

## Come vengono gestiti i duplicati

Ci sono due livelli.

**1. Prevenzione automatica.** Gli URL non si confrontano come testo semplice ma tramite una chiave normalizzata (`gwc.url_key`). La chiave ignora http/https, `www.`, porte di default, `/` finale e frammento `#`, e ordina i parametri della query senza distinguere maiuscole nei nomi. Per esempio questi due URL hanno la stessa chiave:

```
HTTPS://www.Geo.it:443/wms/?SERVICE=WMS&request=GetCapabilities
http://geo.it/wms?request=GetCapabilities&service=WMS
```

Per questo la stessa sorgente non può essere inserita due volte, e lo stesso URL non viene ripetuto sulla stessa risorsa.

**2. Segnalazione da verificare.** La funzione `gwc.detect_duplicates()` cerca coppie sospette con tre criteri, in ordine di affidabilità:

| Criterio | Punteggio |
|---|---|
| `same_external_id`: stesso identificativo di metadato (es. `fileIdentifier` ISO) | 1.0 |
| `same_url`: condividono un URL di accesso. Vale per i file da scaricare, per i servizi con lo stesso layer, o quando entrambe le risorse sono servizi (molti dataset diversi puntano allo stesso WMS). Un URL usato da più di 5 risorse è considerato generico e non conta | 1.0 |
| `similar_title`: titoli simili (soglia 0.85 regolabile), con gli stessi numeri (anni, fogli, sezioni) e aree che si sovrappongono (non basta che si tocchino) | 0.85–1.0 |

Durante la raccolta il controllo si fa su ogni record appena salvato, usando gli indici, quindi resta veloce anche con archivi grandi. Un record già visto nella stessa sorgente aggiorna la risorsa esistente invece di crearne una nuova; se quella risorsa era stata unita a un'altra, i nuovi link vanno alla risorsa tenuta.

Le coppie trovate finiscono in `duplicate_candidates` con stato `pending`. Dall'interfaccia (vista `v_duplicates_pending`) puoi:

- **unire** con `gwc.merge_resources(tieni, scarta)`: punti di accesso, provenienze ed enti passano alla risorsa tenuta, i metadati mancanti vengono completati, e l'altra resta in archivio con stato `duplicate` e un riferimento a quella tenuta (nulla viene cancellato);
- **scartare** con `gwc.reject_duplicate(id)`: la coppia non verrà più proposta.

## Stato delle risorse

| Stato | Significato |
|---|---|
| `active` | Risorsa valida |
| `pending` | Da revisionare (per esempio metadati incompleti) |
| `duplicate` | Unita a un'altra risorsa (`canonical_id`) |
| `rejected` | Scartata manualmente (non pertinente, spam…) |
| `removed` | Non più presente in nessuna delle sorgenti in cui era stata trovata (vedi `resource_sources.removed_at`); torna `active` se ricompare |

## Raccolta incrementale e record rimossi

`harvest_runs.mode` indica se la raccolta era completa (`full`) o incrementale (`incremental`, solo i record modificati da `since`). `harvest_runs.complete` è vero quando una raccolta completa ha letto tutta la sorgente (nessun errore di rete, nessun limite di record raggiunto): solo allora `gwc.mark_removed(sorgente, raccolta)` segna come rimossi i record non più visti.

## Enti normalizzati

`organizations.canonical_id` collega una variante all'ente principale. La vista `v_organizations` dà per ogni ente l'ente principale (`main_id`, `main_name`) e il nome di base usato per i suggerimenti (`gwc.org_group_key`, es. "REGIONE PIEMONTE - Settore X" → "regione piemonte").

## Sorgenti proposte

Le sorgenti trovate nei link delle risorse hanno `origin = 'discovered'`, sono disattivate e hanno `discovery_status` = `proposed`; diventano `accepted` (e attive) o `ignored` dall'interfaccia. `discovered_count` dice quante risorse citano il servizio.

## Duplicati: regole automatiche

La vista `gwc.v_duplicate_features` calcola per ogni coppia da verificare: somiglianza dei titoli (`title_sim`), titolo identico, stesso tipo, stesso identificativo, URL in comune, stesso ente (contando gli enti riuniti), sovrapposizione delle aree (`bbox_overlap`: area comune / area totale) e stessa sorgente. Le regole di `src/Repository/DuplicateRules.php` usano queste colonne per unire o scartare in automatico, con anteprima.

## File esterni

`gwc.files` contiene i riferimenti ai file salvati fuori dal database, nella cartella `storage_dir`: tipo (`kind`), percorso relativo, tipo MIME, dimensione, sha256, numero di righe e informazioni aggiuntive (`meta`, JSON). `gwc.downloads.file_id` e le tabelle SPARQL puntano qui. Vale per tutti i passi del progetto.

## SPARQL

| Tabella | Contenuto |
|---|---|
| `sparql_endpoints` | endpoint (URL e opzioni JSON: `virtuoso`, `page_size`, `max_rows`, `timeout`, `paging`) |
| `sparql_searches` | ricerche fatte: parole chiave, tipo, query spedita, esito e tempi |
| `sparql_groups` | gruppi di dati trovati: una classe (`item_iri`) in un grafo (`graph_iri`) oppure un dataset DCAT; numero di oggetti, esempio, parole chiave, dati geografici riconosciuti (`geo`), profilo delle proprieta' (`profile`), colonne scelte (`columns`), selezione |
| `sparql_downloads` | scaricamenti: formato, colonne, esito, oggetti, pagine, file CSV/RDF e GeoJSON prodotti, registro |

## Viste per l'interfaccia

- `v_resources`: elenco risorse con licenza, enti, formati, tipi di servizio e numero di punti di accesso funzionanti.
- `v_duplicates_pending`: coppie da verificare, affiancate.

## Aggiornare lo schema in futuro

Le modifiche si fanno aggiungendo nuovi file `sql/004_...sql`, `sql/005_...sql` e lanciando `php bin/migrate.php`, che applica solo quelli nuovi. I file già applicati non vanno modificati.
