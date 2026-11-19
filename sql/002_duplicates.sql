-- =====================================================================
-- Migrazione 002: rilevamento e gestione dei duplicati, viste
-- =====================================================================
SET search_path = gwc, public;

-- Cerca possibili duplicati e li inserisce in duplicate_candidates.
--   p_resource_id: se indicato, confronta solo quella risorsa con le altre
--                  (da chiamare dopo l'inserimento di ogni record);
--                  se NULL, ricontrolla tutto l'archivio.
--   p_min_similarity: soglia di somiglianza dei titoli (0..1).
-- Restituisce il numero di nuove coppie trovate.
-- Le coppie gia' decise (confermate o scartate) non vengono riproposte.
CREATE OR REPLACE FUNCTION gwc.detect_duplicates(
    p_resource_id    bigint DEFAULT NULL,
    p_min_similarity real   DEFAULT 0.85
) RETURNS integer LANGUAGE plpgsql AS
$$
DECLARE
    n integer;
BEGIN
    WITH pool AS (
        SELECT * FROM gwc.resources WHERE status IN ('active','pending')
    ),
    pairs AS (
        -- 1) stesso identificativo esterno
        SELECT a.id AS x, b.id AS y, 'same_external_id'::text AS reason, 1.0::real AS score
          FROM pool a JOIN pool b
            ON a.external_id = b.external_id AND a.id < b.id
         WHERE (p_resource_id IS NULL OR p_resource_id IN (a.id, b.id))
        UNION ALL
        -- 2) condividono almeno un URL di accesso (stesso servizio/file)
        SELECT DISTINCT LEAST(ea.resource_id, eb.resource_id), GREATEST(ea.resource_id, eb.resource_id),
               'same_url', 1.0
          FROM gwc.endpoints ea
          JOIN gwc.endpoints eb
            ON ea.url_key = eb.url_key
           AND ea.resource_id <> eb.resource_id
           AND ea.layer_name IS NOT DISTINCT FROM eb.layer_name
          JOIN pool pa ON pa.id = ea.resource_id
          JOIN pool pb ON pb.id = eb.resource_id
         WHERE (p_resource_id IS NULL OR p_resource_id IN (ea.resource_id, eb.resource_id))
        UNION ALL
        -- 3) titolo molto simile e area geografica compatibile
        SELECT a.id, b.id, 'similar_title', similarity(a.title_key, b.title_key)
          FROM pool a JOIN pool b
            ON a.id < b.id
           AND a.title_key % b.title_key
         WHERE (p_resource_id IS NULL OR p_resource_id IN (a.id, b.id))
           AND similarity(a.title_key, b.title_key) >= p_min_similarity
           AND (a.bbox IS NULL OR b.bbox IS NULL OR ST_Intersects(a.bbox, b.bbox))
    ),
    best AS (
        -- per ogni coppia tieni il motivo piu' forte
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

-- Unisce la risorsa p_drop dentro p_keep:
--  * sposta punti di accesso, sorgenti ed enti che p_keep non ha gia'
--  * completa i metadati mancanti di p_keep con quelli di p_drop
--  * marca p_drop come 'duplicate' con canonical_id = p_keep
--  * chiude come 'confirmed' la coppia candidata, se esiste
CREATE OR REPLACE FUNCTION gwc.merge_resources(p_keep bigint, p_drop bigint, p_note text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql AS
$$
DECLARE
    d gwc.resources;
BEGIN
    IF p_keep = p_drop THEN
        RAISE EXCEPTION 'Le due risorse coincidono (%).', p_keep;
    END IF;
    SELECT * INTO d FROM gwc.resources WHERE id = p_drop FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Risorsa % inesistente', p_drop; END IF;
    PERFORM 1 FROM gwc.resources WHERE id = p_keep AND status <> 'duplicate' FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Risorsa % inesistente o gia'' marcata come duplicato', p_keep; END IF;

    -- punti di accesso
    UPDATE gwc.endpoints e SET resource_id = p_keep
     WHERE e.resource_id = p_drop
       AND NOT EXISTS (SELECT 1 FROM gwc.endpoints k
                        WHERE k.resource_id = p_keep AND k.url_key = e.url_key
                          AND k.layer_name IS NOT DISTINCT FROM e.layer_name);

    -- provenienza
    INSERT INTO gwc.resource_sources (resource_id, source_id, record_ref, first_run_id, last_run_id, first_seen_at, last_seen_at)
    SELECT p_keep, source_id, record_ref, first_run_id, last_run_id, first_seen_at, last_seen_at
      FROM gwc.resource_sources WHERE resource_id = p_drop
    ON CONFLICT DO NOTHING;

    -- enti
    INSERT INTO gwc.resource_parties (resource_id, organization_id, role)
    SELECT p_keep, organization_id, role FROM gwc.resource_parties WHERE resource_id = p_drop
    ON CONFLICT DO NOTHING;

    -- metadati mancanti
    UPDATE gwc.resources k SET
        external_id      = COALESCE(k.external_id, d.external_id),
        abstract         = COALESCE(k.abstract, d.abstract),
        keywords         = ARRAY(SELECT DISTINCT unnest(k.keywords || d.keywords)),
        topic_categories = ARRAY(SELECT DISTINCT unnest(k.topic_categories || d.topic_categories)),
        language         = COALESCE(k.language, d.language),
        date_created     = COALESCE(k.date_created, d.date_created),
        date_published   = COALESCE(k.date_published, d.date_published),
        date_revised     = GREATEST(k.date_revised, d.date_revised),
        license_id       = COALESCE(k.license_id, d.license_id),
        license_text     = COALESCE(k.license_text, d.license_text),
        crs              = ARRAY(SELECT DISTINCT unnest(k.crs || d.crs)),
        bbox             = COALESCE(k.bbox, d.bbox),
        first_seen_at    = LEAST(k.first_seen_at, d.first_seen_at),
        last_seen_at     = GREATEST(k.last_seen_at, d.last_seen_at)
     WHERE k.id = p_keep;

    -- le risorse che puntavano a p_drop ora puntano a p_keep
    UPDATE gwc.resources SET canonical_id = p_keep WHERE canonical_id = p_drop;

    UPDATE gwc.resources SET status = 'duplicate', canonical_id = p_keep,
           notes = concat_ws(E'\n', notes, p_note)
     WHERE id = p_drop;

    UPDATE gwc.duplicate_candidates SET status = 'confirmed', decided_at = now(),
           note = COALESCE(p_note, note)
     WHERE resource_a = LEAST(p_keep, p_drop) AND resource_b = GREATEST(p_keep, p_drop);
END
$$;

-- Segna una coppia come "non duplicati": non verra' piu' proposta.
CREATE OR REPLACE FUNCTION gwc.reject_duplicate(p_candidate_id bigint, p_note text DEFAULT NULL)
RETURNS void LANGUAGE sql AS
$$
    UPDATE gwc.duplicate_candidates
       SET status = 'rejected', decided_at = now(), note = COALESCE(p_note, note)
     WHERE id = p_candidate_id;
$$;

-- ---------------------------------------------------------------------
-- Viste per l'interfaccia
-- ---------------------------------------------------------------------

-- Elenco risorse con i dati principali gia' risolti
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
       r.bbox, r.last_seen_at
  FROM gwc.resources r
  LEFT JOIN gwc.licenses l ON l.id = r.license_id;

-- Coppie di duplicati in attesa di decisione, affiancate
CREATE OR REPLACE VIEW gwc.v_duplicates_pending AS
SELECT c.id AS candidate_id, c.reason, c.score, c.detected_at,
       a.id AS a_id, a.title AS a_title, a.external_id AS a_external_id,
       b.id AS b_id, b.title AS b_title, b.external_id AS b_external_id
  FROM gwc.duplicate_candidates c
  JOIN gwc.resources a ON a.id = c.resource_a
  JOIN gwc.resources b ON b.id = c.resource_b
 WHERE c.status = 'pending'
   AND a.status <> 'duplicate' AND b.status <> 'duplicate'
 ORDER BY c.score DESC, c.detected_at;
