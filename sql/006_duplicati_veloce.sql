-- GeoWebCrawler - controllo dei duplicati piu' veloce
--
-- Per chi aveva gia' applicato la prima versione di 006_duplicati_meno_falsi.sql:
--  * i numeri del titolo si calcolano una volta per risorsa (colonna title_nums) invece che per ogni coppia;
--  * il controllo di una singola risorsa (fatto a ogni record raccolto) conta gli URL condivisi
--    solo per i link di quella risorsa, non per tutto l'archivio;
--  * nel controllo di tutto l'archivio ogni coppia si esamina una volta sola.
-- Le coppie gia' trovate restano: non serve ricalcolarle.

-- numeri presenti nel titolo, in ordine e senza separatori delle migliaia
-- (es. 'CTR 1:10.000 sezione 123010' -> '1 10000 123010')
-- Si calcola una volta sola per risorsa, nella colonna title_nums.
ALTER TABLE gwc.resources DROP COLUMN IF EXISTS title_nums;

CREATE OR REPLACE FUNCTION gwc.title_numbers(t text) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$ SELECT coalesce(string_agg(m[1], ' '), '')
     FROM regexp_matches(regexp_replace(regexp_replace(coalesce(t, ''), '(\d)[.'' ](\d{3})(?!\d)', '\1\2', 'g'),
                                        '(\d)[.'' ](\d{3})(?!\d)', '\1\2', 'g'), '(\d+)', 'g') AS m $$;

ALTER TABLE gwc.resources ADD COLUMN title_nums text GENERATED ALWAYS AS (gwc.title_numbers(title)) STORED;

-- nuova firma con un parametro in piu': si toglie la vecchia per evitare chiamate ambigue
DROP FUNCTION IF EXISTS gwc.detect_duplicates(bigint, real);

CREATE OR REPLACE FUNCTION gwc.detect_duplicates(
    p_resource_id    bigint DEFAULT NULL,
    p_min_similarity real   DEFAULT 0.85,
    p_max_url_shared int    DEFAULT 5        -- oltre questo numero di risorse un URL e' "generico"
) RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    n integer;
BEGIN
    PERFORM set_config('pg_trgm.similarity_threshold', p_min_similarity::text, true);

    WITH shared AS (
        -- quante risorse attive usano lo stesso URL (e layer); per una sola risorsa, solo i suoi URL
        SELECT e.url_key, e.layer_name, count(DISTINCT e.resource_id) AS n
          FROM gwc.endpoints e JOIN gwc.resources r ON r.id = e.resource_id AND r.status IN ('active','pending')
         WHERE p_resource_id IS NULL
            OR e.url_key IN (SELECT url_key FROM gwc.endpoints WHERE resource_id = p_resource_id)
         GROUP BY 1, 2
    ),
    pairs AS (
        -- 1) stesso identificativo esterno
        SELECT LEAST(t.id, o.id) AS x, GREATEST(t.id, o.id) AS y, 'same_external_id'::text AS reason, 1.0::real AS score
          FROM gwc.resources t
          JOIN gwc.resources o ON o.external_id = t.external_id AND o.id <> t.id
         WHERE (t.id = p_resource_id OR (p_resource_id IS NULL AND o.id > t.id))
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
        UNION ALL
        -- 2) condividono un URL di accesso specifico (non usato da troppe risorse)
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
           AND (ea.endpoint_type = 'download' OR ea.layer_name IS NOT NULL
                OR (ra.resource_type = 'service' AND rb.resource_type = 'service'))
        UNION ALL
        -- 3) titolo simile, stessi numeri nel titolo e aree che si sovrappongono davvero
        SELECT LEAST(t.id, o.id), GREATEST(t.id, o.id), 'similar_title', similarity(t.title_key, o.title_key)
          FROM gwc.resources t
          JOIN gwc.resources o ON o.title_key % t.title_key AND o.title_nums = t.title_nums AND o.id <> t.id
         WHERE (t.id = p_resource_id OR (p_resource_id IS NULL AND o.id > t.id))
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
           AND (t.bbox IS NULL OR o.bbox IS NULL
                OR (ST_Intersects(t.bbox, o.bbox) AND NOT ST_Touches(t.bbox, o.bbox)))
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
