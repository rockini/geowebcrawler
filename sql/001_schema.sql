-- =====================================================================
-- GeoWebCrawler - schema del database (PostgreSQL 16+ / PostGIS 3)
-- Migrazione 001: struttura di base
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- somiglianza tra testi (duplicati)
CREATE EXTENSION IF NOT EXISTS unaccent;  -- confronto testi senza accenti

CREATE SCHEMA IF NOT EXISTS gwc;
SET search_path = gwc, public;

-- ---------------------------------------------------------------------
-- Funzioni di normalizzazione (usate per riconoscere i duplicati)
-- ---------------------------------------------------------------------

-- unaccent() non e' IMMUTABLE: la incapsuliamo per poterla usare
-- in colonne generate e indici.
CREATE OR REPLACE FUNCTION gwc.immutable_unaccent(t text)
RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
$$ SELECT public.unaccent('public.unaccent'::regdictionary, t) $$;

-- Testo normalizzato: minuscolo, senza accenti, solo lettere/cifre e spazi singoli.
CREATE OR REPLACE FUNCTION gwc.normalize_text(t text)
RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
$$ SELECT btrim(regexp_replace(lower(gwc.immutable_unaccent(t)), '[^a-z0-9]+', ' ', 'g')) $$;

-- Chiave di confronto di un URL:
--  * ignora lo schema (http/https) e il frammento (#...)
--  * host in minuscolo, senza "www." e senza porta di default
--  * senza "/" finale
--  * parametri della query con nome in minuscolo, ordinati alfabeticamente,
--    esclusi quelli vuoti
-- Esempio: HTTPS://www.Geo.it:443/wms/?SERVICE=WMS&request=GetCapabilities
--       -> geo.it/wms?request=GetCapabilities&service=WMS
CREATE OR REPLACE FUNCTION gwc.url_key(u text)
RETURNS text LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE STRICT AS
$$
DECLARE
    m       text[];
    host    text;
    path    text;
    query   text;
BEGIN
    u := btrim(u);
    u := split_part(u, '#', 1);
    m := regexp_match(u, '^(?:[a-zA-Z][a-zA-Z0-9+.-]*://)?([^/?]*)([^?]*)\??(.*)$');
    IF m IS NULL THEN
        RETURN lower(u);
    END IF;
    host := lower(m[1]);
    host := regexp_replace(host, '^www\.', '');
    host := regexp_replace(host, ':(80|443)$', '');
    path := regexp_replace(m[2], '/+$', '');
    SELECT string_agg(p, '&' ORDER BY p) INTO query
      FROM (
        SELECT lower(split_part(kv, '=', 1)) || '=' || substr(kv, length(split_part(kv, '=', 1)) + 2) AS p
          FROM regexp_split_to_table(m[3], '&') AS kv
         WHERE kv <> '' AND kv LIKE '%=%' AND substr(kv, length(split_part(kv, '=', 1)) + 2) <> ''
      ) s;
    RETURN host || path || COALESCE('?' || query, '');
END
$$;

-- Aggiorna automaticamente updated_at
CREATE OR REPLACE FUNCTION gwc.touch_updated_at()
RETURNS trigger LANGUAGE plpgsql AS
$$ BEGIN NEW.updated_at := now(); RETURN NEW; END $$;

-- ---------------------------------------------------------------------
-- Tabelle di riferimento (vocabolari controllati, modificabili da UI)
-- ---------------------------------------------------------------------

CREATE TABLE gwc.source_types (
    code        text PRIMARY KEY,
    label       text NOT NULL,
    phase       smallint NOT NULL DEFAULT 1   -- 1 = prima fase, 2 = versione estesa
);

CREATE TABLE gwc.endpoint_types (
    code        text PRIMARY KEY,
    label       text NOT NULL,
    is_ogc      boolean NOT NULL DEFAULT false
);

CREATE TABLE gwc.formats (
    id          serial PRIMARY KEY,
    code        text NOT NULL UNIQUE,            -- es. 'shp', 'geojson'
    label       text NOT NULL,
    media_type  text,                            -- es. 'application/geo+json'
    kind        text NOT NULL DEFAULT 'other'
                CHECK (kind IN ('vector','raster','tabular','document','archive','other')),
    extensions  text[] NOT NULL DEFAULT '{}'     -- estensioni file riconosciute
);

CREATE TABLE gwc.licenses (
    id          serial PRIMARY KEY,
    code        text NOT NULL UNIQUE,            -- identificativo SPDX quando esiste
    label       text NOT NULL,
    url         text,
    is_open     boolean,
    aliases     text[] NOT NULL DEFAULT '{}'     -- varianti testuali per il riconoscimento
);

-- ---------------------------------------------------------------------
-- Sorgenti: da dove si raccolgono i riferimenti
-- ---------------------------------------------------------------------

CREATE TABLE gwc.sources (
    id              bigserial PRIMARY KEY,
    name            text NOT NULL,
    source_type     text NOT NULL REFERENCES gwc.source_types(code),
    url             text NOT NULL,
    url_key         text GENERATED ALWAYS AS (gwc.url_key(url)) STORED,
    description     text,
    origin          text NOT NULL DEFAULT 'ui' CHECK (origin IN ('config','ui','discovered')),
    enabled         boolean NOT NULL DEFAULT true,
    params          jsonb NOT NULL DEFAULT '{}',   -- opzioni specifiche (filtri CSW, profondita' crawling...)
    harvest_every   interval DEFAULT '7 days',
    last_harvest_at timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (url_key)
);
CREATE TRIGGER sources_touch BEFORE UPDATE ON gwc.sources
    FOR EACH ROW EXECUTE FUNCTION gwc.touch_updated_at();

-- Ogni esecuzione di raccolta su una sorgente
CREATE TABLE gwc.harvest_runs (
    id              bigserial PRIMARY KEY,
    source_id       bigint NOT NULL REFERENCES gwc.sources(id) ON DELETE CASCADE,
    started_at      timestamptz NOT NULL DEFAULT now(),
    finished_at     timestamptz,
    status          text NOT NULL DEFAULT 'running'
                    CHECK (status IN ('running','ok','partial','error')),
    records_found   integer NOT NULL DEFAULT 0,
    records_new     integer NOT NULL DEFAULT 0,
    records_updated integer NOT NULL DEFAULT 0,
    message         text
);
CREATE INDEX harvest_runs_source_idx ON gwc.harvest_runs (source_id, started_at DESC);

-- ---------------------------------------------------------------------
-- Enti / autori
-- ---------------------------------------------------------------------

CREATE TABLE gwc.organizations (
    id              bigserial PRIMARY KEY,
    name            text NOT NULL,
    name_key        text GENERATED ALWAYS AS (gwc.normalize_text(name)) STORED,
    url             text,
    email           text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (name_key)
);

-- ---------------------------------------------------------------------
-- Risorse: i dataset e i servizi trovati, con i metadati
-- ---------------------------------------------------------------------

CREATE TABLE gwc.resources (
    id                  bigserial PRIMARY KEY,
    resource_type       text NOT NULL DEFAULT 'dataset'
                        CHECK (resource_type IN ('dataset','series','service','other')),
    external_id         text,            -- es. fileIdentifier ISO 19139 o dc:identifier
    title               text NOT NULL,
    title_key           text GENERATED ALWAYS AS (gwc.normalize_text(title)) STORED,
    abstract            text,
    keywords            text[] NOT NULL DEFAULT '{}',
    topic_categories    text[] NOT NULL DEFAULT '{}',   -- ISO 19115 topicCategory
    language            text,                           -- es. 'ita'
    date_created        date,
    date_published      date,
    date_revised        date,
    metadata_date       timestamptz,
    update_frequency    text,
    license_id          integer REFERENCES gwc.licenses(id),
    license_text        text,             -- testo originale, anche se non riconosciuto
    access_constraints  text,
    crs                 integer[] NOT NULL DEFAULT '{}',  -- codici EPSG
    bbox                geometry(Polygon, 4326),
    metadata_standard   text,             -- es. 'ISO 19139', 'Dublin Core', 'HTML'
    metadata_url        text,             -- dove leggere i metadati originali
    raw_metadata        text,             -- XML/JSON originale, per rielaborazioni future
    -- gestione qualita' e duplicati
    status              text NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active','pending','duplicate','rejected')),
    canonical_id        bigint REFERENCES gwc.resources(id),  -- se duplicato: la risorsa che si tiene
    notes               text,
    search_vector       tsvector GENERATED ALWAYS AS (
                            setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
                            setweight(to_tsvector('simple', coalesce(abstract, '')), 'B')
                        ) STORED,
    first_seen_at       timestamptz NOT NULL DEFAULT now(),
    last_seen_at        timestamptz NOT NULL DEFAULT now(),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (canonical_id IS NULL OR canonical_id <> id),
    CHECK ((status = 'duplicate') = (canonical_id IS NOT NULL))
);
CREATE TRIGGER resources_touch BEFORE UPDATE ON gwc.resources
    FOR EACH ROW EXECUTE FUNCTION gwc.touch_updated_at();

CREATE INDEX resources_external_id_idx ON gwc.resources (external_id) WHERE external_id IS NOT NULL;
CREATE INDEX resources_title_trgm_idx  ON gwc.resources USING gin (title_key gin_trgm_ops);
CREATE INDEX resources_bbox_idx        ON gwc.resources USING gist (bbox);
CREATE INDEX resources_search_idx      ON gwc.resources USING gin (search_vector);
CREATE INDEX resources_keywords_idx    ON gwc.resources USING gin (keywords);
CREATE INDEX resources_status_idx      ON gwc.resources (status);

-- Ruoli degli enti rispetto alla risorsa (autore, editore, contatto...)
CREATE TABLE gwc.resource_parties (
    resource_id     bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    organization_id bigint NOT NULL REFERENCES gwc.organizations(id),
    role            text NOT NULL DEFAULT 'author'
                    CHECK (role IN ('author','publisher','owner','pointOfContact','distributor','custodian','other')),
    PRIMARY KEY (resource_id, organization_id, role)
);

-- In quali sorgenti e' stata trovata una risorsa (una risorsa puo' comparire in piu' cataloghi)
CREATE TABLE gwc.resource_sources (
    resource_id     bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    source_id       bigint NOT NULL REFERENCES gwc.sources(id) ON DELETE CASCADE,
    record_ref      text NOT NULL DEFAULT '',   -- id del record nella sorgente o URL della pagina
    first_run_id    bigint REFERENCES gwc.harvest_runs(id) ON DELETE SET NULL,
    last_run_id     bigint REFERENCES gwc.harvest_runs(id) ON DELETE SET NULL,
    first_seen_at   timestamptz NOT NULL DEFAULT now(),
    last_seen_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (resource_id, source_id, record_ref)
);
CREATE INDEX resource_sources_source_idx ON gwc.resource_sources (source_id);

-- ---------------------------------------------------------------------
-- Punti di accesso: gli URL concreti (download, WMS, WFS, ...)
-- ---------------------------------------------------------------------

CREATE TABLE gwc.endpoints (
    id              bigserial PRIMARY KEY,
    resource_id     bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    endpoint_type   text NOT NULL REFERENCES gwc.endpoint_types(code),
    url             text NOT NULL,
    url_key         text GENERATED ALWAYS AS (gwc.url_key(url)) STORED,
    format_id       integer REFERENCES gwc.formats(id),
    protocol        text,               -- valore originale, es. 'OGC:WMS-1.3.0-http-get-map'
    service_version text,               -- es. '1.3.0'
    layer_name      text,               -- layer / feature type / coverage
    label           text,
    size_bytes      bigint,
    -- ultimo esito del test di disponibilita' (fase 2), copiato da endpoint_checks
    last_check_at   timestamptz,
    last_check_ok   boolean,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (resource_id, url_key, layer_name)
);
CREATE TRIGGER endpoints_touch BEFORE UPDATE ON gwc.endpoints
    FOR EACH ROW EXECUTE FUNCTION gwc.touch_updated_at();
CREATE INDEX endpoints_url_key_idx ON gwc.endpoints (url_key);
CREATE INDEX endpoints_type_idx    ON gwc.endpoints (endpoint_type);

-- Storico dei test di disponibilita' (fase 2)
CREATE TABLE gwc.endpoint_checks (
    id              bigserial PRIMARY KEY,
    endpoint_id     bigint NOT NULL REFERENCES gwc.endpoints(id) ON DELETE CASCADE,
    checked_at      timestamptz NOT NULL DEFAULT now(),
    ok              boolean NOT NULL,
    http_status     smallint,
    response_ms     integer,
    content_type    text,
    content_length  bigint,
    final_url       text,                -- dopo eventuali redirect
    capabilities_ok boolean,             -- per servizi OGC: GetCapabilities valido
    error           text
);
CREATE INDEX endpoint_checks_endpoint_idx ON gwc.endpoint_checks (endpoint_id, checked_at DESC);

-- Scaricamenti effettuati (fase 3)
CREATE TABLE gwc.downloads (
    id              bigserial PRIMARY KEY,
    endpoint_id     bigint NOT NULL REFERENCES gwc.endpoints(id) ON DELETE CASCADE,
    requested_at    timestamptz NOT NULL DEFAULT now(),
    finished_at     timestamptz,
    status          text NOT NULL DEFAULT 'queued'
                    CHECK (status IN ('queued','running','ok','error','cancelled')),
    local_path      text,
    size_bytes      bigint,
    sha256          char(64),
    imported_table  text,                -- tabella PostGIS se importato
    error           text
);
CREATE INDEX downloads_endpoint_idx ON gwc.downloads (endpoint_id, requested_at DESC);

-- ---------------------------------------------------------------------
-- Duplicati: coppie sospette da confermare o scartare dall'interfaccia
-- ---------------------------------------------------------------------

CREATE TABLE gwc.duplicate_candidates (
    id              bigserial PRIMARY KEY,
    resource_a      bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    resource_b      bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    reason          text NOT NULL CHECK (reason IN ('same_external_id','same_url','similar_title')),
    score           real NOT NULL,       -- 1 = certo, valori minori = somiglianza
    status          text NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','confirmed','rejected')),
    detected_at     timestamptz NOT NULL DEFAULT now(),
    decided_at      timestamptz,
    note            text,
    CHECK (resource_a < resource_b),
    UNIQUE (resource_a, resource_b)
);
CREATE INDEX duplicate_candidates_pending_idx ON gwc.duplicate_candidates (status) WHERE status = 'pending';
