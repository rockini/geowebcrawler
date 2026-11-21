-- GeoWebCrawler - eliminazione dei duplicati piu' automatica
--
-- Per ogni coppia ancora da verificare si calcolano alcune "caratteristiche" (somiglianza del titolo,
-- stesso ente, sovrapposizione delle aree, URL in comune...). Le regole di unione e di scarto
-- automatico (src/Repository/DuplicateRules.php) lavorano su queste caratteristiche.

CREATE OR REPLACE VIEW gwc.v_duplicate_features AS
SELECT c.id AS candidate_id, c.resource_a AS a_id, c.resource_b AS b_id, c.reason, c.score,
       similarity(a.title_key, b.title_key)::real AS title_sim,
       a.title_key = b.title_key AS same_title,
       a.resource_type = b.resource_type AS same_type,
       (a.external_id IS NOT NULL AND a.external_id = b.external_id) AS same_id,
       EXISTS (SELECT 1 FROM gwc.endpoints ea
                 JOIN gwc.endpoints eb ON eb.url_key = ea.url_key AND eb.layer_name IS NOT DISTINCT FROM ea.layer_name
                WHERE ea.resource_id = a.id AND eb.resource_id = b.id) AS same_url,
       -- stesso ente (contando le varianti riunite sotto un ente principale); NULL se un lato non ha enti
       CASE WHEN NOT EXISTS (SELECT 1 FROM gwc.resource_parties WHERE resource_id = a.id)
              OR NOT EXISTS (SELECT 1 FROM gwc.resource_parties WHERE resource_id = b.id) THEN NULL
            ELSE EXISTS (SELECT 1
                           FROM gwc.resource_parties pa JOIN gwc.organizations oa ON oa.id = pa.organization_id,
                                gwc.resource_parties pb JOIN gwc.organizations ob ON ob.id = pb.organization_id
                          WHERE pa.resource_id = a.id AND pb.resource_id = b.id
                            AND COALESCE(oa.canonical_id, oa.id) = COALESCE(ob.canonical_id, ob.id)) END AS same_org,
       -- sovrapposizione delle aree: area comune / area totale (1 = identiche); NULL se manca un'area
       CASE WHEN a.bbox IS NULL OR b.bbox IS NULL THEN NULL
            WHEN ST_Equals(a.bbox, b.bbox) THEN 1.0
            WHEN NOT ST_Intersects(a.bbox, b.bbox) THEN 0.0
            WHEN ST_Area(ST_Union(a.bbox, b.bbox)) = 0 THEN 0.0
            ELSE ST_Area(ST_Intersection(a.bbox, b.bbox)) / ST_Area(ST_Union(a.bbox, b.bbox)) END::real AS bbox_overlap,
       EXISTS (SELECT 1 FROM gwc.resource_sources sa JOIN gwc.resource_sources sb ON sb.source_id = sa.source_id
                WHERE sa.resource_id = a.id AND sb.resource_id = b.id) AS same_source
  FROM gwc.duplicate_candidates c
  JOIN gwc.resources a ON a.id = c.resource_a
  JOIN gwc.resources b ON b.id = c.resource_b
 WHERE c.status = 'pending'
   AND a.status <> 'duplicate' AND b.status <> 'duplicate';
