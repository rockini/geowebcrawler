-- GeoWebCrawler - meno falsi duplicati
--
-- Le regole precedenti generavano troppe coppie:
--  * titoli simili ma con numeri diversi ("Ortofoto 2018" / "Ortofoto 2019", "CTR sezione 123010" / "... 123020")
--    sono quasi sempre dati diversi; con aree confinanti (fogli, tavolette) bastava che i bordi si toccassero;
--  * un URL condiviso da molte risorse (pagina generica del portale, servizio comune) non indica un duplicato.
-- Siccome ogni gruppo di N risorse simili produce N*(N-1)/2 coppie, i numeri crescevano molto in fretta.

-- numeri presenti nel titolo, in ordine (es. 'ctr 1:10000 sezione 123010' -> '1 10000 123010')
CREATE OR REPLACE FUNCTION gwc.title_numbers(t text) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$ SELECT coalesce(string_agg(m[1], ' '), '') FROM regexp_matches(coalesce(t, ''), '(\d+)', 'g') AS m $$;

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
        -- quante risorse attive usano lo stesso URL (e layer)
        SELECT e.url_key, e.layer_name, count(DISTINCT e.resource_id) AS n
          FROM gwc.endpoints e JOIN gwc.resources r ON r.id = e.resource_id AND r.status IN ('active','pending')
         GROUP BY 1, 2
    ),
    pairs AS (
        -- 1) stesso identificativo esterno
        SELECT LEAST(t.id, o.id) AS x, GREATEST(t.id, o.id) AS y, 'same_external_id'::text AS reason, 1.0::real AS score
          FROM gwc.resources t
          JOIN gwc.resources o ON o.external_id = t.external_id AND o.id <> t.id
         WHERE (p_resource_id IS NULL OR t.id = p_resource_id)
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
        UNION ALL
        -- 2) condividono un URL di accesso specifico (non usato da troppe risorse)
        SELECT LEAST(ea.resource_id, eb.resource_id), GREATEST(ea.resource_id, eb.resource_id), 'same_url', 1.0
          FROM gwc.endpoints ea
          JOIN shared sh ON sh.url_key = ea.url_key AND sh.layer_name IS NOT DISTINCT FROM ea.layer_name
                        AND sh.n <= p_max_url_shared
          JOIN gwc.endpoints eb
            ON eb.url_key = ea.url_key
           AND eb.resource_id <> ea.resource_id
           AND eb.layer_name IS NOT DISTINCT FROM ea.layer_name
          JOIN gwc.resources ra ON ra.id = ea.resource_id AND ra.status IN ('active','pending')
          JOIN gwc.resources rb ON rb.id = eb.resource_id AND rb.status IN ('active','pending')
         WHERE (p_resource_id IS NULL OR ea.resource_id = p_resource_id)
           AND (ea.endpoint_type = 'download' OR ea.layer_name IS NOT NULL
                OR (ra.resource_type = 'service' AND rb.resource_type = 'service'))
        UNION ALL
        -- 3) titolo simile, stessi numeri nel titolo e aree che si sovrappongono davvero
        SELECT LEAST(t.id, o.id), GREATEST(t.id, o.id), 'similar_title', similarity(t.title_key, o.title_key)
          FROM gwc.resources t
          JOIN gwc.resources o ON o.title_key % t.title_key AND o.id <> t.id
         WHERE (p_resource_id IS NULL OR t.id = p_resource_id)
           AND t.status IN ('active','pending') AND o.status IN ('active','pending')
           AND similarity(t.title_key, o.title_key) >= p_min_similarity
           AND gwc.title_numbers(t.title) = gwc.title_numbers(o.title)
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

-- ricalcolo: si tolgono solo le coppie ancora da verificare (le decisioni gia' prese restano)
DELETE FROM gwc.duplicate_candidates WHERE status = 'pending';
SELECT gwc.detect_duplicates();
