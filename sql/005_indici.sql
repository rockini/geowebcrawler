-- =====================================================================
-- Migrazione 005: nuovi tipi di indice (passo 2b)
-- =====================================================================
SET search_path = gwc, public;

INSERT INTO gwc.source_types (code, label, phase) VALUES
    ('ogc_service', 'Servizio OGC (GetCapabilities: WMS, WFS, WCS, WMTS)', 1),
    ('dcat',        'Catalogo DCAT-AP / GeoDCAT-AP / DCAT-AP_IT (file RDF)', 1),
    ('stac',        'Catalogo STAC (API o statico)',                         1)
ON CONFLICT (code) DO NOTHING;
UPDATE gwc.source_types SET label = 'Portale open data CKAN (API)' WHERE code = 'ckan';

INSERT INTO gwc.endpoint_types (code, label, is_ogc) VALUES
    ('stac',      'STAC (collezione o API)', false),
    ('sparql',    'Endpoint SPARQL',         false),
    ('rest_json', 'API REST / JSON',         false)
ON CONFLICT (code) DO NOTHING;

INSERT INTO gwc.formats (code, label, media_type, kind, extensions) VALUES
    ('cog',     'Cloud Optimized GeoTIFF', 'image/tiff; application=geotiff; profile=cloud-optimized', 'raster', '{}'),
    ('zarr',    'Zarr',                    'application/vnd+zarr', 'raster', '{zarr}'),
    ('parquet', 'GeoParquet',              'application/vnd.apache.parquet', 'vector', '{parquet}'),
    ('fgb',     'FlatGeobuf',              'application/flatgeobuf', 'vector', '{fgb}')
ON CONFLICT (code) DO NOTHING;

-- Licenze frequenti nei cataloghi europei e STAC (codici SPDX)
INSERT INTO gwc.licenses (code, label, url, is_open, aliases) VALUES
    ('CC-BY-3.0',    'Creative Commons Attribuzione 3.0', 'https://creativecommons.org/licenses/by/3.0/', true, '{cc by 3.0,cc-by 3.0}'),
    ('CC-BY-NC-4.0', 'Creative Commons Attribuzione - Non commerciale 4.0', 'https://creativecommons.org/licenses/by-nc/4.0/', false, '{cc by-nc,cc-by-nc}'),
    ('ODC-By-1.0',   'Open Data Commons Attribution 1.0', 'https://opendatacommons.org/licenses/by/1-0/', true, '{odc-by}'),
    ('PDDL-1.0',     'Open Data Commons Public Domain Dedication', 'https://opendatacommons.org/licenses/pddl/1-0/', true, '{pddl}'),
    ('etalab-2.0',   'Licence Ouverte / Open Licence 2.0', 'https://www.etalab.gouv.fr/licence-ouverte-open-licence/', true, '{licence ouverte,open licence}')
ON CONFLICT (code) DO NOTHING;

-- La licenza IODL 2.0 e CC-BY 4.0 sono indicate nei cataloghi italiani anche con questi URI
UPDATE gwc.licenses SET aliases = aliases || '{iodl20,licenses/iodl-2.0}'::text[] WHERE code = 'IODL-2.0';
UPDATE gwc.licenses SET aliases = aliases || '{cc-by-4.0,cc_by_4_0,licenses/cc-by-4.0,ccby4.0}'::text[] WHERE code = 'CC-BY-4.0';
UPDATE gwc.licenses SET aliases = aliases || '{cc0-1.0,licenses/cc0}'::text[] WHERE code = 'CC0-1.0';
