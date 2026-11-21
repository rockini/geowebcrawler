-- =====================================================================
-- Migrazione 004: correzioni emerse scrivendo la raccolta (passo 2)
-- =====================================================================
SET search_path = gwc, public;

-- 1) Un punto di accesso senza layer deve essere unico per risorsa:
--    con NULLS NOT DISTINCT due righe con layer_name NULL contano come uguali.
ALTER TABLE gwc.endpoints DROP CONSTRAINT IF EXISTS endpoints_resource_id_url_key_layer_name_key;
ALTER TABLE gwc.endpoints ADD CONSTRAINT endpoints_resource_url_layer_key
    UNIQUE NULLS NOT DISTINCT (resource_id, url_key, layer_name);

-- 2) Log testuale della raccolta, consultabile dall'interfaccia
ALTER TABLE gwc.harvest_runs ADD COLUMN IF NOT EXISTS log text;

-- 3) Rilevamento duplicati riscritto per usare gli indici quando si controlla
--    una sola risorsa (caso normale durante la raccolta): evita di confrontare
--    tutte le coppie dell'archivio ad ogni record.
CREATE OR REPLACE FUNCTION gwc.detect_duplicates(
    p_resource_id    bigint DEFAULT NULL,
    p_min_similarity real   DEFAULT 0.85
) RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    n integer;
BEGIN
    -- soglia usata dall'operatore % (cosi' l'indice trigram filtra gia' le coppie)
    PERFORM set_config('pg_trgm.similarity_threshold', p_min_similarity::text, true);

    WITH pairs AS (
        -- 1) stesso identificativo esterno
        SELECT LEAST(t.id, o.id) AS x, GREATEST(t.id, o.id) AS y, 'same_external_id'::text AS reason, 1.0::real AS score
          FROM gwc.resources t
          JOIN gwc.resources o ON o.external_id = t.external_id AND o.id <> t.id
         WHERE (p_resource_id IS NULL OR t.id = p_resource_id)
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
        UNION ALL
        -- 2) condividono un URL di accesso (vedi condizione sotto)
        SELECT LEAST(ea.resource_id, eb.resource_id), GREATEST(ea.resource_id, eb.resource_id), 'same_url', 1.0
          FROM gwc.endpoints ea
          JOIN gwc.endpoints eb
            ON eb.url_key = ea.url_key
           AND eb.resource_id <> ea.resource_id
           AND eb.layer_name IS NOT DISTINCT FROM ea.layer_name
          JOIN gwc.resources ra ON ra.id = ea.resource_id AND ra.status IN ('active','pending')
          JOIN gwc.resources rb ON rb.id = eb.resource_id AND rb.status IN ('active','pending')
         WHERE (p_resource_id IS NULL OR ea.resource_id = p_resource_id)
           -- molti dataset diversi puntano allo stesso servizio WMS/WFS: l'URL conta solo se
           -- e' un file da scaricare, se indica lo stesso layer, o se entrambi sono servizi
           AND (ea.endpoint_type = 'download' OR ea.layer_name IS NOT NULL
                OR (ra.resource_type = 'service' AND rb.resource_type = 'service'))
        UNION ALL
        -- 3) titolo simile e aree compatibili
        SELECT LEAST(t.id, o.id), GREATEST(t.id, o.id), 'similar_title', similarity(t.title_key, o.title_key)
          FROM gwc.resources t
          JOIN gwc.resources o ON o.title_key % t.title_key AND o.id <> t.id
         WHERE (p_resource_id IS NULL OR t.id = p_resource_id)
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
           AND similarity(t.title_key, o.title_key) >= p_min_similarity
           AND (t.bbox IS NULL OR o.bbox IS NULL OR ST_Intersects(t.bbox, o.bbox))
    ),
    best AS (
        SELECT DISTINCT ON (x, y) x, y, reason, score
          FROM pairs
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
