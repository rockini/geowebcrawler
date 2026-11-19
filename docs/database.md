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
| `harvest_runs` | Un record per ogni esecuzione della raccolta su una sorgente: esito, quanti record trovati, nuovi e aggiornati. |
| `resources` | I dataset e i servizi trovati, con i metadati standard: titolo, descrizione, parole chiave, date (creazione, pubblicazione, revisione), licenza, vincoli d'accesso, sistemi di riferimento (EPSG), estensione geografica (`bbox` in WGS84), standard dei metadati e metadati originali (`raw_metadata`). |
| `organizations` / `resource_parties` | Enti e autori, e il loro ruolo rispetto alla risorsa (autore, editore, contatto, distributore…). Lo stesso ente scritto in modi diversi (maiuscole, accenti) viene riconosciuto come uno solo. |
| `resource_sources` | In quali sorgenti è stata vista ogni risorsa, e quando la prima e l'ultima volta. Una risorsa presente in più cataloghi resta **una sola risorsa** con più provenienze. |
| `endpoints` | I punti di accesso concreti: URL di download, WMS, WFS, WCS, WMTS, OGC API, ArcGIS REST, Atom INSPIRE… con formato, versione del servizio e nome del layer. |
| `endpoint_checks` | *(fase 2)* Storico dei test di disponibilità: codice HTTP, tempo di risposta, tipo di contenuto, validità del GetCapabilities. L'ultimo esito è copiato anche in `endpoints.last_check_ok`. |
| `downloads` | *(fase 3)* Scaricamenti eseguiti: file locale, dimensione, impronta SHA-256, eventuale tabella PostGIS in cui il dato è stato importato. |
| `duplicate_candidates` | Coppie di risorse che sembrano duplicate, in attesa che tu le confermi o le scarti dall'interfaccia. |

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
| `same_url`: condividono un URL di accesso (stesso servizio o file) | 1.0 |
| `similar_title`: titoli simili (soglia 0.85 regolabile) e aree geografiche compatibili | 0.85–1.0 |

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

## Viste per l'interfaccia

- `v_resources`: elenco risorse con licenza, enti, formati, tipi di servizio e numero di punti di accesso funzionanti.
- `v_duplicates_pending`: coppie da verificare, affiancate.

## Aggiornare lo schema in futuro

Le modifiche si fanno aggiungendo nuovi file `sql/004_...sql`, `sql/005_...sql` e lanciando `php bin/migrate.php`, che applica solo quelli nuovi. I file già applicati non vanno modificati.
