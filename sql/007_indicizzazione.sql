-- GeoWebCrawler - passo 2c: indicizzazione
--  1. raccolta incrementale e record rimossi dalla sorgente
--  2. collegamenti servizio <-> dataset
--  3. (duplicati a gruppi: solo interfaccia, piu' lo spostamento dei collegamenti quando si unisce)
--  4. (ricerca a faccette: indici)
--  5. enti normalizzati e temi INSPIRE
--  6. scoperta di nuove sorgenti

-- =====================================================================
-- 1. Raccolta incrementale e record rimossi
-- =====================================================================

ALTER TABLE gwc.resources DROP CONSTRAINT IF EXISTS resources_status_check;
ALTER TABLE gwc.resources ADD CONSTRAINT resources_status_check
    CHECK (status IN ('active','pending','duplicate','rejected','removed'));

-- quando la sorgente non contiene piu' il record
ALTER TABLE gwc.resource_sources ADD COLUMN IF NOT EXISTS removed_at timestamptz;

ALTER TABLE gwc.harvest_runs
    ADD COLUMN IF NOT EXISTS mode text NOT NULL DEFAULT 'full' CHECK (mode IN ('full','incremental')),
    ADD COLUMN IF NOT EXISTS since timestamptz,                 -- raccolta incrementale: modificati da
    ADD COLUMN IF NOT EXISTS complete boolean NOT NULL DEFAULT false,  -- letta tutta la sorgente
    ADD COLUMN IF NOT EXISTS records_removed integer NOT NULL DEFAULT 0;

-- Dopo una raccolta completa: i record della sorgente non visti in questa raccolta sono rimossi.
-- Una risorsa diventa 'removed' solo se non e' piu' presente in nessuna sorgente.
CREATE OR REPLACE FUNCTION gwc.mark_removed(p_source bigint, p_run bigint)
RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    n integer;
BEGIN
    UPDATE gwc.resource_sources SET removed_at = now()
     WHERE source_id = p_source AND removed_at IS NULL AND last_run_id IS DISTINCT FROM p_run;

    UPDATE gwc.resources r SET status = 'removed'
     WHERE r.status IN ('active','pending')
       AND EXISTS (SELECT 1 FROM gwc.resource_sources s WHERE s.resource_id = r.id AND s.source_id = p_source)
       AND NOT EXISTS (SELECT 1 FROM gwc.resource_sources s WHERE s.resource_id = r.id AND s.removed_at IS NULL);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END
$$;

-- =====================================================================
-- 2. Collegamenti servizio <-> dataset
-- =====================================================================

-- Chiave di un servizio OGC: url_key senza i parametri della singola richiesta
-- (service, request, layers...), ma con quelli del server (es. map=)
CREATE OR REPLACE FUNCTION gwc.service_key(u text)
RETURNS text LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE STRICT AS
$$
DECLARE
    k text := gwc.url_key(u);
    base text := split_part(k, '?', 1);
    q text;
BEGIN
    IF strpos(k, '?') = 0 THEN
        RETURN base;
    END IF;
    SELECT string_agg(p, '&' ORDER BY p) INTO q
      FROM regexp_split_to_table(substr(k, strpos(k, '?') + 1), '&') AS p
     WHERE split_part(p, '=', 1) NOT IN (
           'service','request','version','acceptversions','layers','layer','typename','typenames','styles',
           'format','outputformat','crs','srs','bbox','width','height','transparent','coverage','coverageid',
           'tilematrixset','tilematrix','tilerow','tilecol','outputschema','elementsetname','resulttype',
           'startposition','maxrecords','id','namespace','typenames','constraint','constraintlanguage',
           'constraint_language_version','sections','language','exceptions','info_format','query_layers',
           'feature_count','x','y','i','j','count','maxfeatures','srsname','propertyname','filter');
    RETURN base || COALESCE('?' || q, '');
END
$$;

ALTER TABLE gwc.endpoints ADD COLUMN IF NOT EXISTS service_key text GENERATED ALWAYS AS (
    CASE WHEN endpoint_type IN ('wms','wfs','wcs','wmts','csw','sos') THEN gwc.service_key(url) END) STORED;
CREATE INDEX IF NOT EXISTS endpoints_service_key_idx ON gwc.endpoints (service_key) WHERE service_key IS NOT NULL;

-- Collegamento: il servizio "service_id" pubblica il dataset "dataset_id"
CREATE TABLE IF NOT EXISTS gwc.resource_links (
    service_id  bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    dataset_id  bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    origin      text NOT NULL CHECK (origin IN ('metadata','endpoint')),
                -- metadata: dichiarato nei metadati (srv:operatesOn, dcat:servesDataset)
                -- endpoint: il dataset ha un link a un layer dello stesso servizio
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (service_id, dataset_id),
    CHECK (service_id <> dataset_id)
);
CREATE INDEX IF NOT EXISTS resource_links_dataset_idx ON gwc.resource_links (dataset_id);

-- Riferimenti ai dataset dichiarati nei metadati del servizio, da risolvere
CREATE INDEX IF NOT EXISTS resources_metadata_url_key_idx ON gwc.resources (gwc.url_key(metadata_url))
    WHERE metadata_url IS NOT NULL;

CREATE TABLE IF NOT EXISTS gwc.resource_link_refs (
    resource_id bigint NOT NULL REFERENCES gwc.resources(id) ON DELETE CASCADE,
    ref         text NOT NULL,     -- identificativo (uuid) o URL del metadato del dataset
    PRIMARY KEY (resource_id, ref)
);

-- Identificativo contenuto in un riferimento: parametro id/uuid/fileIdentifier di un URL,
-- frammento (#...) o il riferimento stesso
CREATE OR REPLACE FUNCTION gwc.ref_id(ref text)
RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
$$ SELECT COALESCE(
       (regexp_match(ref, '[?&](?:id|uuid|fileidentifier|identifier)=([^&#]+)', 'i'))[1],
       (regexp_match(ref, '#([^#/]+)$'))[1],
       ref) $$;

-- Risolve i collegamenti (per una risorsa o per tutto l'archivio). Restituisce i nuovi collegamenti.
CREATE OR REPLACE FUNCTION gwc.resolve_links(p_resource_id bigint DEFAULT NULL)
RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    n1 integer;
    n2 integer;
BEGIN
    -- a) dichiarati nei metadati del servizio: per identificativo o per URL del metadato
    INSERT INTO gwc.resource_links (service_id, dataset_id, origin)
    SELECT DISTINCT COALESCE(s.canonical_id, s.id), COALESCE(d.canonical_id, d.id), 'metadata'
      FROM gwc.resource_link_refs lr
      JOIN gwc.resources s ON s.id = lr.resource_id
      JOIN gwc.resources d ON d.external_id = lr.ref OR d.external_id = gwc.ref_id(lr.ref)
     WHERE (p_resource_id IS NULL OR lr.resource_id = p_resource_id OR d.id = p_resource_id)
       AND d.resource_type <> 'service'
       AND COALESCE(s.canonical_id, s.id) <> COALESCE(d.canonical_id, d.id)
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n1 = ROW_COUNT;

    INSERT INTO gwc.resource_links (service_id, dataset_id, origin)
    SELECT DISTINCT COALESCE(s.canonical_id, s.id), COALESCE(d.canonical_id, d.id), 'metadata'
      FROM gwc.resource_link_refs lr
      JOIN gwc.resources s ON s.id = lr.resource_id
      JOIN gwc.resources d ON d.metadata_url IS NOT NULL AND gwc.url_key(d.metadata_url) = gwc.url_key(lr.ref)
     WHERE lr.ref ~* '^https?://'
       AND (p_resource_id IS NULL OR lr.resource_id = p_resource_id OR d.id = p_resource_id)
       AND d.resource_type <> 'service'
       AND COALESCE(s.canonical_id, s.id) <> COALESCE(d.canonical_id, d.id)
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n2 = ROW_COUNT;
    n1 := n1 + n2;

    -- b) il dataset ha un link a un layer di un servizio descritto da un'altra risorsa
    INSERT INTO gwc.resource_links (service_id, dataset_id, origin)
    SELECT DISTINCT s.id, d.id, 'endpoint'
      FROM gwc.endpoints es
      JOIN gwc.resources s  ON s.id = es.resource_id AND s.resource_type = 'service' AND s.status IN ('active','pending')
      JOIN gwc.endpoints ed ON ed.service_key = es.service_key AND ed.endpoint_type = es.endpoint_type
                           AND ed.layer_name IS NOT NULL AND ed.resource_id <> es.resource_id
      JOIN gwc.resources d  ON d.id = ed.resource_id AND d.resource_type <> 'service' AND d.status IN ('active','pending')
     WHERE es.service_key IS NOT NULL AND es.layer_name IS NULL
       AND (p_resource_id IS NULL OR s.id = p_resource_id OR d.id = p_resource_id)
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n2 = ROW_COUNT;

    -- un servizio e il dataset che pubblica non sono duplicati
    UPDATE gwc.duplicate_candidates c SET status = 'rejected', decided_at = now(),
           note = 'servizio e dataset collegati'
      FROM gwc.resource_links l
     WHERE c.status = 'pending'
       AND c.resource_a = LEAST(l.service_id, l.dataset_id) AND c.resource_b = GREATEST(l.service_id, l.dataset_id);

    RETURN n1 + n2;
END
$$;

-- Quando una risorsa viene unita a un'altra, i suoi collegamenti passano a quella tenuta
CREATE OR REPLACE FUNCTION gwc.move_links_on_merge()
RETURNS trigger LANGUAGE plpgsql AS
$$
BEGIN
    IF NEW.status = 'duplicate' AND OLD.status IS DISTINCT FROM 'duplicate' AND NEW.canonical_id IS NOT NULL THEN
        INSERT INTO gwc.resource_links (service_id, dataset_id, origin)
        SELECT CASE WHEN service_id = NEW.id THEN NEW.canonical_id ELSE service_id END,
               CASE WHEN dataset_id = NEW.id THEN NEW.canonical_id ELSE dataset_id END, origin
          FROM gwc.resource_links
         WHERE NEW.id IN (service_id, dataset_id)
           AND (CASE WHEN service_id = NEW.id THEN NEW.canonical_id ELSE service_id END)
               <> (CASE WHEN dataset_id = NEW.id THEN NEW.canonical_id ELSE dataset_id END)
        ON CONFLICT DO NOTHING;
        DELETE FROM gwc.resource_links WHERE NEW.id IN (service_id, dataset_id);
        INSERT INTO gwc.resource_link_refs (resource_id, ref)
        SELECT NEW.canonical_id, ref FROM gwc.resource_link_refs WHERE resource_id = NEW.id
        ON CONFLICT DO NOTHING;
        PERFORM gwc.assign_themes(NEW.canonical_id);
    END IF;
    RETURN NEW;
END
$$;

-- =====================================================================
-- 5a. Enti: varianti dello stesso ente riunite sotto un ente principale
-- =====================================================================

ALTER TABLE gwc.organizations ADD COLUMN IF NOT EXISTS canonical_id bigint REFERENCES gwc.organizations(id);
ALTER TABLE gwc.organizations DROP CONSTRAINT IF EXISTS organizations_canonical_check;
ALTER TABLE gwc.organizations ADD CONSTRAINT organizations_canonical_check CHECK (canonical_id IS NULL OR canonical_id <> id);
CREATE INDEX IF NOT EXISTS organizations_name_trgm_idx ON gwc.organizations USING gin (name_key gin_trgm_ops);
CREATE INDEX IF NOT EXISTS resource_parties_org_idx ON gwc.resource_parties (organization_id);

-- Nome "di gruppo" di un ente: senza la parte dopo " - ", ",", "(" ... (settori, uffici, sigle)
-- es. 'REGIONE PIEMONTE - Settore Cartografico' -> 'regione piemonte'
CREATE OR REPLACE FUNCTION gwc.org_group_key(name text)
RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
$$ SELECT gwc.normalize_text(regexp_replace(name, '\s*(\s[-–—|/]\s|,|\(|;|:).*$', '')) $$;

-- Ente principale di ogni ente
CREATE OR REPLACE VIEW gwc.v_organizations AS
SELECT o.id, o.name, o.email, o.url, o.canonical_id,
       COALESCE(o.canonical_id, o.id) AS main_id,
       COALESCE(m.name, o.name) AS main_name,
       gwc.org_group_key(o.name) AS group_key
  FROM gwc.organizations o
  LEFT JOIN gwc.organizations m ON m.id = o.canonical_id;

-- =====================================================================
-- 5b. Temi INSPIRE
-- =====================================================================

CREATE TABLE IF NOT EXISTS gwc.themes (
    code     text PRIMARY KEY,           -- codice INSPIRE, es. 'hy'
    label    text NOT NULL,              -- italiano
    label_en text NOT NULL,
    uri      text NOT NULL,
    annex    smallint NOT NULL
);

INSERT INTO gwc.themes (code, label, label_en, annex, uri)
SELECT c, l, le, a, 'http://inspire.ec.europa.eu/theme/' || c FROM (VALUES
    ('ad', 'Indirizzi', 'Addresses', 1),
    ('au', 'Unità amministrative', 'Administrative units', 1),
    ('cp', 'Parcelle catastali', 'Cadastral parcels', 1),
    ('gg', 'Sistemi di griglie geografiche', 'Geographical grid systems', 1),
    ('gn', 'Nomi geografici', 'Geographical names', 1),
    ('hy', 'Idrografia', 'Hydrography', 1),
    ('ps', 'Siti protetti', 'Protected sites', 1),
    ('rs', 'Sistemi di coordinate', 'Coordinate reference systems', 1),
    ('tn', 'Reti di trasporto', 'Transport networks', 1),
    ('el', 'Elevazione', 'Elevation', 2),
    ('ge', 'Geologia', 'Geology', 2),
    ('lc', 'Copertura del suolo', 'Land cover', 2),
    ('oi', 'Orto immagini', 'Orthoimagery', 2),
    ('ac', 'Condizioni atmosferiche', 'Atmospheric conditions', 3),
    ('af', 'Impianti agricoli e di acquacoltura', 'Agricultural and aquaculture facilities', 3),
    ('am', 'Zone sottoposte a gestione, limitazioni o regolamentazione', 'Area management/restriction/regulation zones and reporting units', 3),
    ('br', 'Regioni biogeografiche', 'Bio-geographical regions', 3),
    ('bu', 'Edifici', 'Buildings', 3),
    ('ef', 'Impianti di monitoraggio ambientale', 'Environmental monitoring facilities', 3),
    ('er', 'Risorse energetiche', 'Energy resources', 3),
    ('hb', 'Habitat e biotopi', 'Habitats and biotopes', 3),
    ('hh', 'Salute umana e sicurezza', 'Human health and safety', 3),
    ('lu', 'Utilizzo del territorio', 'Land use', 3),
    ('mf', 'Elementi geografici meteorologici', 'Meteorological geographical features', 3),
    ('mr', 'Risorse minerarie', 'Mineral resources', 3),
    ('nz', 'Zone a rischio naturale', 'Natural risk zones', 3),
    ('of', 'Elementi geografici oceanografici', 'Oceanographic geographical features', 3),
    ('pd', 'Distribuzione della popolazione - demografia', 'Population distribution - demography', 3),
    ('pf', 'Produzione e impianti industriali', 'Production and industrial facilities', 3),
    ('sd', 'Distribuzione delle specie', 'Species distribution', 3),
    ('so', 'Suolo', 'Soil', 3),
    ('sr', 'Regioni marine', 'Sea regions', 3),
    ('su', 'Unità statistiche', 'Statistical units', 3),
    ('us', 'Servizi di pubblica utilità e servizi amministrativi', 'Utility and governmental services', 3)
) AS v (c, l, le, a)
ON CONFLICT (code) DO NOTHING;

-- Regole di riconoscimento: espressione regolare (sintassi PostgreSQL) applicata
-- alle parole chiave normalizzate (minuscole, senza accenti), oppure alle categorie ISO.
-- Se una risorsa non ha parole chiave riconosciute, le regole 'keyword' si applicano al titolo.
-- Si possono aggiungere regole con un INSERT e poi rieseguire SELECT gwc.assign_themes();
CREATE TABLE IF NOT EXISTS gwc.theme_rules (
    id      serial PRIMARY KEY,
    theme   text NOT NULL REFERENCES gwc.themes(code) ON DELETE CASCADE,
    field   text NOT NULL CHECK (field IN ('keyword','topic')),
    pattern text NOT NULL,
    UNIQUE (theme, field, pattern)
);

-- l'etichetta del tema (italiano e inglese) e l'URI del registro INSPIRE
INSERT INTO gwc.theme_rules (theme, field, pattern)
SELECT code, 'keyword', '^' || gwc.normalize_text(label) || '$' FROM gwc.themes
UNION ALL SELECT code, 'keyword', '^' || gwc.normalize_text(label_en) || '$' FROM gwc.themes
UNION ALL SELECT code, 'keyword', 'inspire ec europa eu theme ' || code || '\M' FROM gwc.themes
UNION ALL SELECT code, 'topic', '^' || code || '$' FROM gwc.themes   -- dcat:theme con URI INSPIRE
ON CONFLICT DO NOTHING;

-- categorie tematiche ISO 19115 (topicCategory)
INSERT INTO gwc.theme_rules (theme, field, pattern) VALUES
    ('au', 'topic', '^boundaries$'),
    ('hy', 'topic', '^inlandwaters$'),
    ('el', 'topic', '^elevation$'),
    ('tn', 'topic', '^transportation$'),
    ('ge', 'topic', '^geoscientificinformation$'),
    ('oi', 'topic', '^imagerybasemapsearthcover$'),
    ('bu', 'topic', '^structure$'),
    ('us', 'topic', '^utilitiescommunication$'),
    ('af', 'topic', '^farming$'),
    ('hh', 'topic', '^health$'),
    ('of', 'topic', '^oceans$'),
    ('mf', 'topic', '^climatologymeteorologyatmosphere$'),
    ('lu', 'topic', '^planningcadastre$'),
    ('sd', 'topic', '^biota$'),
    ('pd', 'topic', '^society$')
ON CONFLICT DO NOTHING;

-- parole chiave frequenti (italiano e inglese)
INSERT INTO gwc.theme_rules (theme, field, pattern) VALUES
    ('ad', 'keyword', '\mindirizz'), ('ad', 'keyword', '\maddress'), ('ad', 'keyword', '\mnumer[io] civic'),
    ('au', 'keyword', '\mconfini? amministrativ'), ('au', 'keyword', '\mlimiti amministrativ'),
    ('au', 'keyword', '\mlimiti comunali'), ('au', 'keyword', '\madministrative boundar'),
    ('cp', 'keyword', '\mcatast'), ('cp', 'keyword', '\mcadastr'), ('cp', 'keyword', '\mparticell'),
    ('gn', 'keyword', '\mtoponom'), ('gn', 'keyword', '\mplace names?\M'),
    ('hy', 'keyword', '\midrograf'), ('hy', 'keyword', '\mhydrograph'), ('hy', 'keyword', '\mfium[ei]\M'),
    ('hy', 'keyword', '\mlagh[io]\M'), ('hy', 'keyword', '\mcorsi d acqua'), ('hy', 'keyword', '\mreticolo idric'),
    ('hy', 'keyword', '\mbacin[io] idrograf'), ('hy', 'keyword', '\mrivers?\M'), ('hy', 'keyword', '\macque superficiali'),
    ('ps', 'keyword', '\maree protette'), ('ps', 'keyword', '\mnatura 2000'), ('ps', 'keyword', '\mparchi\M'),
    ('ps', 'keyword', '\mriserve? natural'), ('ps', 'keyword', '\m(zps|sic|zsc)\M'), ('ps', 'keyword', '\mprotected areas?'),
    ('rs', 'keyword', '\mgeodet'), ('rs', 'keyword', '\mreference system'),
    ('tn', 'keyword', '\mstrad[ae]\M'), ('tn', 'keyword', '\mstradal'), ('tn', 'keyword', '\mferrovi'),
    ('tn', 'keyword', '\mviabilit'), ('tn', 'keyword', '\maeroport'), ('tn', 'keyword', '\mporti\M'),
    ('tn', 'keyword', '\mrailways?\M'), ('tn', 'keyword', '\mroads?\M'), ('tn', 'keyword', '\mpiste ciclabili'),
    ('el', 'keyword', '\melevation'), ('el', 'keyword', '\m(dtm|dem|dsm)\M'), ('el', 'keyword', '\maltimetri'),
    ('el', 'keyword', '\mlidar\M'), ('el', 'keyword', '\mcurve di livello'), ('el', 'keyword', '\mmodello digitale'),
    ('ge', 'keyword', '\mgeolog'), ('ge', 'keyword', '\mlitolog'), ('ge', 'keyword', '\midrogeolog'),
    ('ge', 'keyword', '\mgeomorfolog'),
    ('lc', 'keyword', '\mland cover'), ('lc', 'keyword', '\mcorine\M'), ('lc', 'keyword', '\mcopertura del suolo'),
    ('oi', 'keyword', '\mortofot'), ('oi', 'keyword', '\morto ?immagin'), ('oi', 'keyword', '\morthoimag'),
    ('oi', 'keyword', '\morthophoto'), ('oi', 'keyword', '\mimmagini satellitari'), ('oi', 'keyword', '\mfoto aere'),
    ('ac', 'keyword', '\mqualita dell aria'), ('ac', 'keyword', '\mair quality'), ('ac', 'keyword', '\minquinamento atmosferic'),
    ('af', 'keyword', '\macquacoltur'), ('af', 'keyword', '\mallevament'), ('af', 'keyword', '\maziende agricole'),
    ('am', 'keyword', '\mvincol'), ('am', 'keyword', '\mfasce di rispetto'), ('am', 'keyword', '\mzonizzazion'),
    ('am', 'keyword', '\mzone vulnerabili'),
    ('bu', 'keyword', '\medifici'), ('bu', 'keyword', '\mbuildings?\M'), ('bu', 'keyword', '\mfabbricat'),
    ('ef', 'keyword', '\mstazioni di (monitoraggio|misura|rilevamento)'), ('ef', 'keyword', '\mcentraline\M'),
    ('ef', 'keyword', '\mmonitoring (stations|network)'),
    ('er', 'keyword', '\menergi'), ('er', 'keyword', '\menergy\M'), ('er', 'keyword', '\mfotovoltaic'),
    ('er', 'keyword', '\meolic'),
    ('hb', 'keyword', '\mhabitat'), ('hb', 'keyword', '\mbiotop'),
    ('hh', 'keyword', '\msanit'), ('hh', 'keyword', '\mospedal'), ('hh', 'keyword', '\mhealth\M'),
    ('lu', 'keyword', '\mland use'), ('lu', 'keyword', '\muso del suolo'), ('lu', 'keyword', '\mpian[oi] regolator'),
    ('lu', 'keyword', '\murbanistic'), ('lu', 'keyword', '\mpianificazione'), ('lu', 'keyword', '\m(prg|pgt|puc|ptcp|ptr|ppr)\M'),
    ('lu', 'keyword', '\mpiano (territoriale|paesaggistico|paesistico)'),
    ('mf', 'keyword', '\mmeteo'), ('mf', 'keyword', '\mclimat'), ('mf', 'keyword', '\mprecipitazion'),
    ('mf', 'keyword', '\mtemperatur'), ('mf', 'keyword', '\mpluviometr'),
    ('mr', 'keyword', '\mcave\M'), ('mr', 'keyword', '\mminier'), ('mr', 'keyword', '\mattivita estrattiv'),
    ('mr', 'keyword', '\mmineral'),
    ('nz', 'keyword', '\mrischio'), ('nz', 'keyword', '\mfran[ae]\M'), ('nz', 'keyword', '\mfranosit'),
    ('nz', 'keyword', '\malluvion'), ('nz', 'keyword', '\mpericolosita'), ('nz', 'keyword', '\mpai\M'),
    ('nz', 'keyword', '\msismic'), ('nz', 'keyword', '\mvalangh'), ('nz', 'keyword', '\mesondaz'),
    ('nz', 'keyword', '\mdissesto'), ('nz', 'keyword', '\mflood'), ('nz', 'keyword', '\mlandslide'),
    ('nz', 'keyword', '\mincendi'),
    ('of', 'keyword', '\mbatimetri'), ('of', 'keyword', '\mbathymetr'), ('of', 'keyword', '\mmoto ondoso'),
    ('pd', 'keyword', '\mdemograf'), ('pd', 'keyword', '\mpopolazione\M'), ('pd', 'keyword', '\mpopulation\M'),
    ('pf', 'keyword', '\mimpianti industriali'), ('pf', 'keyword', '\mindustri'), ('pf', 'keyword', '\mstabiliment'),
    ('sd', 'keyword', '\mspecie\M'), ('sd', 'keyword', '\mflora\M'), ('sd', 'keyword', '\mfauna\M'),
    ('sd', 'keyword', '\mspecies\M'),
    ('so', 'keyword', '(?<!uso del )(?<!copertura del )(?<!consumo di )\msuol[oi]\M'), ('so', 'keyword', '\mconsumo di suolo'),
    ('so', 'keyword', '\msoils?\M'), ('so', 'keyword', '\mpedolog'),
    ('sr', 'keyword', '\mlinea di costa'), ('sr', 'keyword', '\mcost[ae]\M'), ('sr', 'keyword', '\mcoastline'),
    ('sr', 'keyword', '\mambiente marino'),
    ('su', 'keyword', '\msezioni di censimento'), ('su', 'keyword', '\mcensus'), ('su', 'keyword', '\mistat\M'),
    ('us', 'keyword', '\macquedott'), ('us', 'keyword', '\mfognatur'), ('us', 'keyword', '\mreti tecnologiche'),
    ('us', 'keyword', '\mscuol[ae]\M'), ('us', 'keyword', '\mrifiuti'), ('us', 'keyword', '\melettrodott'),
    ('us', 'keyword', '\mgasdott'), ('us', 'keyword', '\mservizi pubblici'), ('us', 'keyword', '\mprotezione civile')
ON CONFLICT DO NOTHING;

ALTER TABLE gwc.resources ADD COLUMN IF NOT EXISTS themes text[] NOT NULL DEFAULT '{}';
CREATE INDEX IF NOT EXISTS resources_themes_idx ON gwc.resources USING gin (themes);

-- Assegna i temi INSPIRE a una risorsa (o a tutte). Restituisce le risorse modificate.
-- Per tutto l'archivio si procede tema per tema con un'unica espressione regolare per tema
-- (PostgreSQL tiene in memoria solo poche espressioni compilate: centinaia di regole alternate
-- verrebbero ricompilate di continuo) e le parole chiave normalizzate si calcolano una volta sola.
CREATE OR REPLACE FUNCTION gwc.assign_themes(p_resource_id bigint DEFAULT NULL)
RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    n  integer;
    th record;
BEGIN
    IF p_resource_id IS NOT NULL THEN
        -- una sola risorsa (durante la raccolta): poche parole chiave, si provano le regole una per una
        WITH kw AS (
            SELECT r.id, r.title_key,
                   ARRAY(SELECT DISTINCT tr.theme FROM gwc.theme_rules tr
                          WHERE (tr.field = 'keyword' AND EXISTS (
                                     SELECT 1 FROM unnest(r.keywords) k WHERE gwc.normalize_text(k) ~ tr.pattern))
                             OR (tr.field = 'topic' AND EXISTS (
                                     SELECT 1 FROM unnest(r.topic_categories) t WHERE lower(t) ~ tr.pattern))
                          ORDER BY 1) AS themes
              FROM gwc.resources r WHERE r.id = p_resource_id
        ), calc AS (
            SELECT kw.id,
                   CASE WHEN kw.themes <> '{}' THEN kw.themes
                        ELSE ARRAY(SELECT DISTINCT tr.theme FROM gwc.theme_rules tr
                                    WHERE tr.field = 'keyword' AND kw.title_key ~ tr.pattern ORDER BY 1) END AS themes
              FROM kw
        )
        UPDATE gwc.resources r SET themes = c.themes FROM calc c
         WHERE r.id = c.id AND r.themes IS DISTINCT FROM c.themes;
        GET DIAGNOSTICS n = ROW_COUNT;
        RETURN n;
    END IF;

    -- tutto l'archivio
    CREATE TEMP TABLE IF NOT EXISTS gwc_kw (id bigint, k text) ON COMMIT DROP;
    CREATE TEMP TABLE IF NOT EXISTS gwc_found (id bigint, theme text, src smallint) ON COMMIT DROP;
    TRUNCATE gwc_kw, gwc_found;
    INSERT INTO gwc_kw SELECT DISTINCT r.id, gwc.normalize_text(k)
      FROM gwc.resources r, unnest(r.keywords) k WHERE k <> '';
    INSERT INTO gwc_kw SELECT DISTINCT r.id, 'topic:' || lower(t)
      FROM gwc.resources r, unnest(r.topic_categories) t WHERE t <> '';

    FOR th IN SELECT theme,
                     string_agg('(?:' || pattern || ')', '|') FILTER (WHERE field = 'keyword') AS kw,
                     string_agg('(?:' || pattern || ')', '|') FILTER (WHERE field = 'topic') AS tp
                FROM gwc.theme_rules GROUP BY theme
    LOOP
        IF th.kw IS NOT NULL THEN
            INSERT INTO gwc_found SELECT DISTINCT id, th.theme, 1 FROM gwc_kw
             WHERE k NOT LIKE 'topic:%' AND k ~ th.kw;
            INSERT INTO gwc_found SELECT id, th.theme, 2 FROM gwc.resources WHERE title_key ~ th.kw;
        END IF;
        IF th.tp IS NOT NULL THEN
            INSERT INTO gwc_found SELECT DISTINCT id, th.theme, 1 FROM gwc_kw
             WHERE k LIKE 'topic:%' AND substr(k, 7) ~ th.tp;
        END IF;
    END LOOP;

    -- i temi dalle parole chiave e dalle categorie; se non ce ne sono, quelli dal titolo
    WITH calc AS (
        SELECT r.id, COALESCE(k.themes, t.themes, '{}') AS themes
          FROM gwc.resources r
          LEFT JOIN (SELECT id, array_agg(DISTINCT theme ORDER BY theme) AS themes FROM gwc_found WHERE src = 1 GROUP BY id) k
                 ON k.id = r.id
          LEFT JOIN (SELECT id, array_agg(DISTINCT theme ORDER BY theme) AS themes FROM gwc_found WHERE src = 2 GROUP BY id) t
                 ON t.id = r.id
    )
    UPDATE gwc.resources r SET themes = c.themes FROM calc c
     WHERE r.id = c.id AND r.themes IS DISTINCT FROM c.themes;
    GET DIAGNOSTICS n = ROW_COUNT;
    TRUNCATE gwc_kw, gwc_found;
    RETURN n;
END
$$;

-- il trigger usa assign_themes: si crea dopo
DROP TRIGGER IF EXISTS resources_merge_links ON gwc.resources;
CREATE TRIGGER resources_merge_links AFTER UPDATE OF status ON gwc.resources
    FOR EACH ROW EXECUTE FUNCTION gwc.move_links_on_merge();

-- =====================================================================
-- 6. Scoperta di nuove sorgenti
-- =====================================================================

ALTER TABLE gwc.sources
    ADD COLUMN IF NOT EXISTS discovery_status text CHECK (discovery_status IN ('proposed','accepted','ignored')),
    ADD COLUMN IF NOT EXISTS discovered_count integer;   -- quante risorse citano questo servizio

-- =====================================================================
-- Duplicati: niente coppie tra un servizio e un dataset, ne' tra risorse collegate
-- =====================================================================


CREATE OR REPLACE FUNCTION gwc.detect_duplicates(
    p_resource_id    bigint DEFAULT NULL,
    p_min_similarity real   DEFAULT 0.85,
    p_max_url_shared int    DEFAULT 5
) RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    n integer;
BEGIN
    PERFORM set_config('pg_trgm.similarity_threshold', p_min_similarity::text, true);

    WITH shared AS (
        SELECT e.url_key, e.layer_name, count(DISTINCT e.resource_id) AS n
          FROM gwc.endpoints e JOIN gwc.resources r ON r.id = e.resource_id AND r.status IN ('active','pending')
         WHERE p_resource_id IS NULL
            OR e.url_key IN (SELECT url_key FROM gwc.endpoints WHERE resource_id = p_resource_id)
         GROUP BY 1, 2
    ),
    pairs AS (
        SELECT LEAST(t.id, o.id) AS x, GREATEST(t.id, o.id) AS y, 'same_external_id'::text AS reason, 1.0::real AS score
          FROM gwc.resources t
          JOIN gwc.resources o ON o.external_id = t.external_id AND o.id <> t.id
         WHERE (t.id = p_resource_id OR (p_resource_id IS NULL AND o.id > t.id))
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
        UNION ALL
        SELECT LEAST(ea.resource_id, eb.resource_id), GREATEST(ea.resource_id, eb.resource_id), 'same_url', 1.0
          FROM gwc.endpoints ea
          JOIN shared sh ON sh.url_key = ea.url_key AND sh.layer_name IS NOT DISTINCT FROM ea.layer_name
                        AND sh.n BETWEEN 2 AND p_max_url_shared
          JOIN gwc.endpoints eb
            ON eb.url_key = ea.url_key
           AND eb.resource_id <> ea.resource_id
           AND eb.layer_name IS NOT DISTINCT FROM ea.layer_name
          JOIN gwc.resources ra ON ra.id = ea.resource_id AND ra.status IN ('active','pending')
          JOIN gwc.resources rb ON rb.id = eb.resource_id AND rb.status IN ('active','pending')
         WHERE (ea.resource_id = p_resource_id OR (p_resource_id IS NULL AND eb.resource_id > ea.resource_id))
           AND (ra.resource_type = 'service') = (rb.resource_type = 'service')
           AND (ea.endpoint_type = 'download' OR ea.layer_name IS NOT NULL
                OR (ra.resource_type = 'service' AND rb.resource_type = 'service'))
        UNION ALL
        SELECT LEAST(t.id, o.id), GREATEST(t.id, o.id), 'similar_title', similarity(t.title_key, o.title_key)
          FROM gwc.resources t
          JOIN gwc.resources o ON o.title_key % t.title_key AND o.title_nums = t.title_nums AND o.id <> t.id
         WHERE (t.id = p_resource_id OR (p_resource_id IS NULL AND o.id > t.id))
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
           AND (t.resource_type = 'service') = (o.resource_type = 'service')
           AND (t.bbox IS NULL OR o.bbox IS NULL
                OR (ST_Intersects(t.bbox, o.bbox) AND NOT ST_Touches(t.bbox, o.bbox)))
    ),
    best AS (
        SELECT DISTINCT ON (x, y) x, y, reason, score
          FROM pairs
         WHERE NOT EXISTS (SELECT 1 FROM gwc.resource_links l
                            WHERE (l.service_id = pairs.x AND l.dataset_id = pairs.y)
                               OR (l.service_id = pairs.y AND l.dataset_id = pairs.x))
         ORDER BY x, y, score DESC, reason
    ),
    ins AS (
        INSERT INTO gwc.duplicate_candidates (resource_a, resource_b, reason, score)
        SELECT x, y, reason, score FROM best
        ON CONFLICT (resource_a, resource_b) DO NOTHING
        RETURNING 1
    )
    SELECT count(*) INTO n FROM ins;
    RETURN n;
END
$$;

-- coppie in attesa tra un servizio e un dataset: non sono duplicati
DELETE FROM gwc.duplicate_candidates c
 USING gwc.resources a, gwc.resources b
 WHERE c.status = 'pending' AND a.id = c.resource_a AND b.id = c.resource_b
   AND (a.resource_type = 'service') <> (b.resource_type = 'service');

-- v_resources con i temi
CREATE OR REPLACE VIEW gwc.v_resources AS
SELECT r.id, r.resource_type, r.title, r.status, r.date_published, r.date_revised,
       l.code  AS license_code, COALESCE(l.label, r.license_text) AS license,
       (SELECT string_agg(DISTINCT o.name, '; ')
          FROM gwc.resource_parties p JOIN gwc.organizations o ON o.id = p.organization_id
         WHERE p.resource_id = r.id) AS organizations,
       (SELECT string_agg(DISTINCT f.code, ', ')
          FROM gwc.endpoints e JOIN gwc.formats f ON f.id = e.format_id
         WHERE e.resource_id = r.id) AS formats,
       (SELECT string_agg(DISTINCT e.endpoint_type, ', ')
          FROM gwc.endpoints e WHERE e.resource_id = r.id) AS endpoint_types,
       (SELECT count(*) FROM gwc.endpoints e WHERE e.resource_id = r.id) AS n_endpoints,
       (SELECT count(*) FROM gwc.endpoints e WHERE e.resource_id = r.id AND e.last_check_ok) AS n_endpoints_ok,
       (SELECT count(*) FROM gwc.resource_sources s WHERE s.resource_id = r.id) AS n_sources,
       r.bbox, r.last_seen_at, r.themes
  FROM gwc.resources r
  LEFT JOIN gwc.licenses l ON l.id = r.license_id;

-- prima assegnazione dei temi e dei collegamenti sull'archivio esistente
SELECT gwc.assign_themes();
SELECT gwc.resolve_links();
