-- GeoWebCrawler - fase 2: verifica della disponibilita' dei servizi e dei link
--
-- Un "obiettivo" di verifica e' cio' a cui si fa davvero una richiesta:
--  * per i servizi OGC (wms, wfs, wcs, wmts, csw, sos) il servizio, una volta sola per tutti i suoi layer
--    (chiave: tipo|service_key); si chiede il GetCapabilities e si controlla che ogni layer citato ci sia;
--  * per gli altri punti di accesso (download, pagine, Atom, ArcGIS REST...) l'indirizzo (chiave: link|url_key).
-- gwc.check_targets tiene l'ultimo esito di ogni obiettivo, gwc.endpoint_checks lo storico per punto di accesso,
-- gwc.check_batches le verifiche lanciate (dall'interfaccia o dalla riga di comando).

-- stessa regola di endpoints.service_key (007): i tipi OGC si raggruppano per servizio
CREATE OR REPLACE FUNCTION gwc.check_key(p_type text, p_url text)
RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$ SELECT CASE WHEN p_type IN ('wms','wfs','wcs','wmts','csw','sos') THEN p_type || '|' || gwc.service_key(p_url)
               ELSE 'link|' || gwc.url_key(p_url) END $$;

ALTER TABLE gwc.endpoints
    ADD COLUMN IF NOT EXISTS check_key text GENERATED ALWAYS AS (gwc.check_key(endpoint_type, url)) STORED,
    ADD COLUMN IF NOT EXISTS last_check_status text CHECK (last_check_status IN ('ok','warn','error')),
    ADD COLUMN IF NOT EXISTS last_check_note text;
CREATE INDEX IF NOT EXISTS endpoints_check_key_idx ON gwc.endpoints (check_key);
CREATE INDEX IF NOT EXISTS endpoints_last_check_idx ON gwc.endpoints (last_check_status);

CREATE TABLE gwc.check_batches (
    id              bigserial PRIMARY KEY,
    created_at      timestamptz NOT NULL DEFAULT now(),
    started_at      timestamptz,
    finished_at     timestamptz,
    origin          text NOT NULL DEFAULT 'web',           -- web | cli
    filter          jsonb NOT NULL DEFAULT '{}',           -- filtri (o elenco di obiettivi) usati per scegliere cosa provare
    description     text,
    status          text NOT NULL DEFAULT 'queued'
                    CHECK (status IN ('queued','running','stopping','stopped','done','error')),
    n_targets       integer NOT NULL DEFAULT 0,
    n_done          integer NOT NULL DEFAULT 0,
    n_ok            integer NOT NULL DEFAULT 0,
    n_warn          integer NOT NULL DEFAULT 0,
    n_error         integer NOT NULL DEFAULT 0,
    message         text
);

CREATE TABLE gwc.check_targets (
    check_key       text PRIMARY KEY,
    kind            text NOT NULL,                         -- tipo OGC (wms, wfs...) oppure 'link'
    url             text NOT NULL,                         -- indirizzo effettivamente chiamato
    last_check_at   timestamptz,
    last_status     text CHECK (last_status IN ('ok','warn','error')),
    last_ok_at      timestamptz,
    http_status     smallint,
    response_ms     integer,
    content_type    text,
    content_length  bigint,
    final_url       text,
    service_title   text,
    service_version text,
    n_layers        integer,
    note            text,                                  -- avvisi o errore, in italiano
    n_checks        integer NOT NULL DEFAULT 0,
    n_failures      integer NOT NULL DEFAULT 0,            -- errori consecutivi
    last_batch_id   bigint REFERENCES gwc.check_batches(id) ON DELETE SET NULL
);
CREATE INDEX check_targets_status_idx ON gwc.check_targets (last_status, kind);

ALTER TABLE gwc.endpoint_checks
    ADD COLUMN IF NOT EXISTS status      text CHECK (status IN ('ok','warn','error')),
    ADD COLUMN IF NOT EXISTS check_key   text,
    ADD COLUMN IF NOT EXISTS batch_id    bigint REFERENCES gwc.check_batches(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS layer_found boolean,
    ADD COLUMN IF NOT EXISTS note        text;
CREATE INDEX IF NOT EXISTS endpoint_checks_endpoint_idx ON gwc.endpoint_checks (endpoint_id, checked_at DESC);
CREATE INDEX IF NOT EXISTS endpoint_checks_key_idx ON gwc.endpoint_checks (check_key, checked_at DESC);
