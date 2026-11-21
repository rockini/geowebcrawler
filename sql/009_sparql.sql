-- GeoWebCrawler - file esterni e interrogazione di endpoint SPARQL
--
-- 1) gwc.files: i dati di grosse dimensioni (scaricamenti, estrazioni SPARQL...) restano in file nella cartella
--    "storage"; il database ne conserva solo il riferimento (percorso relativo, dimensione, impronta sha256).
--    Vale per tutti i passi successivi del progetto.
-- 2) Endpoint SPARQL (es. https://dati.cultura.gov.it/sparql), ricerche per parole chiave, "gruppi di dati"
--    trovati (una classe RDF in un grafo, oppure un dataset DCAT) e loro scaricamento in file.

CREATE TABLE gwc.files (
    id              bigserial PRIMARY KEY,
    kind            text NOT NULL,               -- es. 'sparql', 'download'
    path            text NOT NULL UNIQUE,        -- relativo alla cartella storage, con "/"
    original_name   text,
    media_type      text,
    size_bytes      bigint,
    sha256          char(64),
    n_rows          bigint,                      -- righe o elementi, se ha senso
    meta            jsonb NOT NULL DEFAULT '{}',
    created_at      timestamptz NOT NULL DEFAULT now()
);
COMMENT ON TABLE gwc.files IS 'File salvati fuori dal database (cartella storage): qui solo i riferimenti';

ALTER TABLE gwc.downloads ADD COLUMN IF NOT EXISTS file_id bigint REFERENCES gwc.files(id) ON DELETE SET NULL;

CREATE TABLE gwc.sparql_endpoints (
    id              serial PRIMARY KEY,
    name            text NOT NULL,
    url             text NOT NULL UNIQUE,
    description     text,
    -- opzioni: virtuoso (true: usa bif:contains e i parametri di Virtuoso), page_size (soggetti per pagina),
    -- max_rows (righe massime restituite dal server), timeout (secondi)
    options         jsonb NOT NULL DEFAULT '{}',
    enabled         boolean NOT NULL DEFAULT true,
    last_ok_at      timestamptz,
    last_error      text,
    created_at      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE gwc.sparql_searches (
    id              bigserial PRIMARY KEY,
    endpoint_id     integer NOT NULL REFERENCES gwc.sparql_endpoints(id) ON DELETE CASCADE,
    keywords        text NOT NULL,
    mode            text NOT NULL,               -- classes | text | datasets | query
    geo_only        boolean NOT NULL DEFAULT false,
    query           text NOT NULL,
    started_at      timestamptz NOT NULL DEFAULT now(),
    ms              integer,
    n_rows          integer,
    n_new           integer,
    status          text NOT NULL DEFAULT 'running' CHECK (status IN ('running','ok','error')),
    error           text
);

-- Un gruppo di dati: le istanze di una classe in un grafo (kind = 'class'), oppure un dataset DCAT/VoID
-- descritto nell'endpoint (kind = 'dataset', item_iri = IRI del dataset).
CREATE TABLE gwc.sparql_groups (
    id              bigserial PRIMARY KEY,
    endpoint_id     integer NOT NULL REFERENCES gwc.sparql_endpoints(id) ON DELETE CASCADE,
    kind            text NOT NULL DEFAULT 'class' CHECK (kind IN ('class','dataset')),
    graph_iri       text NOT NULL DEFAULT '',    -- '' = grafo non indicato
    item_iri        text NOT NULL,               -- classe o dataset
    label           text,
    description     text,
    n_instances     bigint,
    sample_iri      text,
    sample_text     text,
    urls            text[] NOT NULL DEFAULT '{}',   -- per i dataset: indirizzi di scaricamento
    keywords        text[] NOT NULL DEFAULT '{}',   -- parole chiave delle ricerche che lo hanno trovato
    geo             text[] NOT NULL DEFAULT '{}',   -- coordinate | wkt | indirizzo
    profile         jsonb,                          -- proprieta' trovate (vedi src/Sparql/Profiler.php)
    profiled_at     timestamptz,
    columns         jsonb,                          -- colonne scelte per lo scaricamento
    selected        boolean NOT NULL DEFAULT false,
    first_search_id bigint REFERENCES gwc.sparql_searches(id) ON DELETE SET NULL,
    first_seen_at   timestamptz NOT NULL DEFAULT now(),
    last_seen_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (endpoint_id, graph_iri, item_iri)
);
CREATE INDEX sparql_groups_keywords ON gwc.sparql_groups USING gin (keywords);
CREATE INDEX sparql_groups_label ON gwc.sparql_groups USING gin (label gin_trgm_ops);

CREATE TABLE gwc.sparql_downloads (
    id              bigserial PRIMARY KEY,
    group_id        bigint NOT NULL REFERENCES gwc.sparql_groups(id) ON DELETE CASCADE,
    format          text NOT NULL DEFAULT 'csv' CHECK (format IN ('csv','rdf','file')),
    columns         jsonb,
    query           text,                         -- query della prima pagina
    status          text NOT NULL DEFAULT 'running' CHECK (status IN ('running','ok','partial','error')),
    n_subjects      bigint NOT NULL DEFAULT 0,
    n_rows          bigint NOT NULL DEFAULT 0,
    n_pages         integer NOT NULL DEFAULT 0,
    file_id         bigint REFERENCES gwc.files(id) ON DELETE SET NULL,
    geojson_file_id bigint REFERENCES gwc.files(id) ON DELETE SET NULL,
    started_at      timestamptz NOT NULL DEFAULT now(),
    finished_at     timestamptz,
    error           text,
    log             text
);
CREATE INDEX sparql_downloads_group ON gwc.sparql_downloads (group_id);

INSERT INTO gwc.sparql_endpoints (name, url, description, options) VALUES
  ('Ministero della Cultura - dati.cultura.gov.it', 'https://dati.cultura.gov.it/sparql',
   'Linked open data del MiC (luoghi della cultura, beni culturali, ArCo, Cultural-ON). Server OpenLink Virtuoso.',
   '{"virtuoso": true, "page_size": 2000, "max_rows": 10000, "timeout": 120}')
ON CONFLICT (url) DO NOTHING;
