-- =====================================================================
-- Migrazione 003: valori iniziali dei vocabolari
-- (si possono ampliare in seguito dall'interfaccia)
-- =====================================================================
SET search_path = gwc, public;

INSERT INTO gwc.source_types (code, label, phase) VALUES
    ('csw',      'Catalogo CSW (OGC Catalogue Service)', 1),
    ('webpage',  'Pagina web con link a dati o servizi', 1),
    ('ckan',     'Portale open data CKAN',               1),
    ('manual',   'Inserimento manuale',                  1),
    ('feed',     'Feed RSS/Atom (riviste, notizie)',     2),
    ('blog',     'Blog di settore',                      2),
    ('social',   'Social network',                       2)
ON CONFLICT (code) DO NOTHING;

INSERT INTO gwc.endpoint_types (code, label, is_ogc) VALUES
    ('download',        'Download diretto di file',      false),
    ('wms',             'OGC WMS',                       true),
    ('wmts',            'OGC WMTS',                      true),
    ('wfs',             'OGC WFS',                       true),
    ('wcs',             'OGC WCS',                       true),
    ('csw',             'OGC CSW',                       true),
    ('sos',             'OGC SOS',                       true),
    ('ogcapi_features', 'OGC API - Features',            true),
    ('ogcapi_tiles',    'OGC API - Tiles',               true),
    ('arcgis_rest',     'ArcGIS REST',                   false),
    ('atom',            'INSPIRE Atom download service', false),
    ('webpage',         'Pagina web informativa',        false),
    ('other',           'Altro',                         false)
ON CONFLICT (code) DO NOTHING;

INSERT INTO gwc.formats (code, label, media_type, kind, extensions) VALUES
    ('shp',      'ESRI Shapefile',     'application/x-shapefile',          'vector',  '{shp}'),
    ('geojson',  'GeoJSON',            'application/geo+json',             'vector',  '{geojson,json}'),
    ('gpkg',     'GeoPackage',         'application/geopackage+sqlite3',   'vector',  '{gpkg}'),
    ('gml',      'GML',                'application/gml+xml',              'vector',  '{gml}'),
    ('kml',      'KML',                'application/vnd.google-earth.kml+xml', 'vector', '{kml}'),
    ('kmz',      'KMZ',                'application/vnd.google-earth.kmz', 'vector',  '{kmz}'),
    ('dxf',      'DXF',                'image/vnd.dxf',                    'vector',  '{dxf}'),
    ('geotiff',  'GeoTIFF',            'image/tiff',                       'raster',  '{tif,tiff}'),
    ('ascii_grid','ESRI ASCII Grid',   'text/plain',                       'raster',  '{asc}'),
    ('jp2',      'JPEG 2000',          'image/jp2',                        'raster',  '{jp2}'),
    ('ecw',      'ECW',                'image/ecw',                        'raster',  '{ecw}'),
    ('las',      'LAS/LAZ (LiDAR)',    'application/vnd.las',              'raster',  '{las,laz}'),
    ('netcdf',   'NetCDF',             'application/x-netcdf',             'raster',  '{nc}'),
    ('csv',      'CSV',                'text/csv',                         'tabular', '{csv}'),
    ('xlsx',     'Excel',              'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'tabular', '{xls,xlsx}'),
    ('zip',      'Archivio ZIP',       'application/zip',                  'archive', '{zip}'),
    ('7z',       'Archivio 7z',        'application/x-7z-compressed',      'archive', '{7z}'),
    ('pdf',      'PDF',                'application/pdf',                  'document','{pdf}'),
    ('other',    'Altro / sconosciuto', NULL,                              'other',   '{}')
ON CONFLICT (code) DO NOTHING;

INSERT INTO gwc.licenses (code, label, url, is_open, aliases) VALUES
    ('CC-BY-4.0',    'Creative Commons Attribuzione 4.0',               'https://creativecommons.org/licenses/by/4.0/',    true,  '{cc-by,cc by 4.0,cc-by 4.0,creative commons attribuzione}'),
    ('CC-BY-SA-4.0', 'Creative Commons Attribuzione - Condividi allo stesso modo 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', true, '{cc-by-sa,cc by-sa 4.0}'),
    ('CC0-1.0',      'Creative Commons Zero (pubblico dominio)',        'https://creativecommons.org/publicdomain/zero/1.0/', true, '{cc0,cc-zero,public domain}'),
    ('IODL-2.0',     'Italian Open Data License 2.0',                   'https://www.dati.gov.it/iodl/2.0/',                true,  '{iodl,iodl 2.0,italian open data license}'),
    ('ODbL-1.0',     'Open Database License 1.0',                       'https://opendatacommons.org/licenses/odbl/1-0/',   true,  '{odbl}'),
    ('OGL-UK-3.0',   'Open Government Licence v3.0',                    'https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/', true, '{ogl}'),
    ('unknown',      'Non specificata',                                  NULL,                                               NULL,  '{}'),
    ('restricted',   'Uso riservato / non aperta',                       NULL,                                               false, '{}')
ON CONFLICT (code) DO NOTHING;
