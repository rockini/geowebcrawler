<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use GeoWebCrawler\Http\HttpClient;

/**
 * Raccoglie i dataset da un portale CKAN con l'API package_search.
 * L'URL della sorgente e' l'indirizzo del portale (es. https://www.dati.gov.it/opendata)
 * oppure direttamente .../api/3/action/package_search.
 *
 * Parametri della sorgente (campo params):
 *   geo_only     true (default): chiede al portale solo i dataset con risorse in formati geografici
 *                e scarta quelli senza link a dati o servizi geografici
 *   q            ricerca libera (sintassi Solr), es. "idrografia"
 *   fq           filtro Solr aggiuntivo, es. "organization:regione-toscana"
 *   max_records  numero massimo di dataset (default: harvest.max_records in config.php, 25000)
 *   page_size    dataset per richiesta (default 100, massimo 1000)
 */
final class CkanHarvester implements Harvester
{
    /** Valori di res_format usati dai portali per i formati geografici (il campo distingue maiuscole/minuscole). */
    private const GEO_FORMATS = [
        'SHP', 'shp', 'Shapefile', 'ESRI Shapefile', 'ZIP SHP', 'WMS', 'wms', 'OGC WMS', 'WFS', 'wfs', 'OGC WFS',
        'WCS', 'WMTS', 'GeoJSON', 'geojson', 'GEOJSON', 'KML', 'kml', 'KMZ', 'GML', 'gml', 'GPKG', 'GeoPackage',
        'GeoTIFF', 'geotiff', 'TIFF', 'DXF', 'dxf', 'DWG', 'ArcGIS GeoServices REST API', 'Esri REST', 'esri rest',
    ];

    /** Formato CKAN (minuscolo) -> codice gwc.formats */
    private const FORMAT_MAP = [
        'shp' => 'shp', 'shapefile' => 'shp', 'esri shapefile' => 'shp', 'zip shp' => 'shp',
        'geojson' => 'geojson', 'json' => null, 'kml' => 'kml', 'kmz' => 'kmz', 'gml' => 'gml',
        'gpkg' => 'gpkg', 'geopackage' => 'gpkg', 'geotiff' => 'geotiff', 'tiff' => 'geotiff', 'tif' => 'geotiff',
        'dxf' => 'dxf', 'dwg' => 'dxf', 'csv' => 'csv', 'xlsx' => 'xlsx', 'xls' => 'xlsx', 'zip' => 'zip',
        'pdf' => 'pdf', 'las' => 'las', 'laz' => 'las', 'netcdf' => 'netcdf', 'nc' => 'netcdf',
    ];

    public function __construct(
        private HttpClient $http = new HttpClient(),
        private EndpointClassifier $classifier = new EndpointClassifier(),
    ) {}

    public function harvest(array $source, callable $log): \Generator
    {
        $p = $source['params'] ?? [];
        [$api, $portal] = self::apiUrl($source['url']);
        $geoOnly  = (bool) ($p['geo_only'] ?? true);
        $max      = max(1, (int) ($p['max_records'] ?? \GeoWebCrawler\Config::get('harvest.max_records', 25000)));
        $pageSize = max(1, min(1000, (int) ($p['page_size'] ?? 100)));

        $fq = [];
        if (!empty($p['fq'])) {
            $fq[] = '(' . $p['fq'] . ')';
        }
        if ($geoOnly) {
            $fq[] = 'res_format:(' . implode(' OR ', array_map(fn($f) => '"' . $f . '"', self::GEO_FORMATS)) . ')';
        }

        $start = 0;
        $read = 0;
        $skipped = 0;
        while ($read < $max) {
            $query = ['rows' => min($pageSize, $max - $read), 'start' => $start, 'sort' => 'metadata_modified desc'];
            if (!empty($p['q'])) {
                $query['q'] = $p['q'];
            }
            if ($fq) {
                $query['fq'] = implode(' AND ', $fq);
            }
            $res = $this->http->get($api . '?' . http_build_query($query), ['Accept: application/json']);
            $data = json_decode($res->body, true);
            if (!is_array($data) || empty($data['success'])) {
                $err = is_array($data) ? json_encode($data['error'] ?? 'success=false', JSON_UNESCAPED_UNICODE) : $res->problem();
                throw new \RuntimeException("Errore dall'API CKAN: $err");
            }
            $results = $data['result']['results'] ?? [];
            if ($start === 0) {
                $log(sprintf('Dataset trovati sul portale: %d (ne leggo al massimo %d)', (int) ($data['result']['count'] ?? 0), $max));
            }
            if (!$results) {
                break;
            }
            foreach ($results as $pkg) {
                $read++;
                $r = $this->toRecord($pkg, $portal);
                if ($geoOnly && !self::hasGeoEndpoint($r)) {
                    $skipped++;
                    continue;
                }
                yield $r;
            }
            $start += count($results);
            if ($start >= (int) ($data['result']['count'] ?? 0)) {
                break;
            }
        }
        if ($skipped) {
            $log("Scartati $skipped dataset senza link a dati o servizi geografici");
        }
    }

    public function toRecord(array $pkg, string $portal): Record
    {
        $extras = [];
        foreach ($pkg['extras'] ?? [] as $e) {
            if (isset($e['key'])) {
                $extras[$e['key']] = $e['value'] ?? null;
            }
        }
        $x = fn(string ...$keys) => self::first(array_map(fn($k) => $pkg[$k] ?? $extras[$k] ?? null, $keys));

        $r = new Record((string) ($pkg['id'] ?? $pkg['name']), trim((string) ($pkg['title'] ?: $pkg['name'])));
        $r->externalId = $x('identifier', 'guid') ?? ($pkg['name'] ?? null);
        $r->abstract = $x('notes');
        $r->keywords = array_values(array_unique(array_filter(array_map(
            fn($t) => is_array($t) ? ($t['display_name'] ?? $t['name'] ?? null) : $t, $pkg['tags'] ?? []))));
        $themes = $x('theme');
        if ($themes) {
            $decoded = json_decode((string) $themes, true);
            $r->topicCategories = array_values(array_filter(array_map(fn($t) => is_array($t) ? ($t['theme'] ?? null) : $t,
                is_array($decoded) ? $decoded : [$themes])));
        }
        $r->language = $x('language');
        $r->datePublished = Xml::date($x('issued') ?? $pkg['metadata_created'] ?? null);
        $r->dateRevised = Xml::date($x('modified') ?? $pkg['metadata_modified'] ?? null);
        $r->updateFrequency = self::lastSegment($x('frequency', 'accrual_periodicity'));
        $r->licenseText = implode(' | ', array_filter([$pkg['license_id'] ?? null, $pkg['license_title'] ?? null, $pkg['license_url'] ?? null])) ?: null;
        $r->metadataStandard = 'CKAN' . (isset($extras['dcat_type']) || isset($pkg['holder_name']) ? ' (DCAT-AP_IT)' : '');
        $r->metadataUrl = rtrim($portal, '/') . '/dataset/' . rawurlencode((string) ($pkg['name'] ?? $pkg['id']));
        $r->rawMetadata = json_encode($pkg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // enti: organizzazione CKAN e campi DCAT-AP_IT (titolare, editore)
        if (!empty($pkg['organization']['title'])) {
            $r->addParty($pkg['organization']['title'], 'publisher', null, $pkg['organization']['image_url'] ?? null);
        }
        foreach ([['holder_name', 'owner'], ['publisher_name', 'publisher'], ['author', 'author'], ['maintainer', 'pointOfContact']] as [$k, $role]) {
            if ($v = $x($k)) {
                $email = $role === 'author' ? ($pkg['author_email'] ?? null) : ($role === 'pointOfContact' ? ($pkg['maintainer_email'] ?? null) : null);
                $r->addParty($v, $role, $email ?: null);
            }
        }

        // estensione: campo "spatial" (GeoJSON) di ckanext-spatial
        if (($spatial = $x('spatial')) && is_array($geo = json_decode((string) $spatial, true))) {
            $r->bbox = Geo::bboxOfGeoJson($geo);
        }

        foreach ($pkg['resources'] ?? [] as $res) {
            $url = trim((string) ($res['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $fmt = strtolower(trim((string) ($res['format'] ?? '')));
            $ep = $this->classifier->classify($url, $fmt !== '' ? $fmt : null, $res['name'] ?? $res['description'] ?? null);
            if ($ep === null) {
                continue;
            }
            if ($ep->type === 'webpage' && isset(self::FORMAT_MAP[$fmt])) {
                $ep->type = 'download';   // link senza estensione ma con formato dichiarato
            }
            if ($ep->type === 'download' && $ep->format === null) {
                $ep->format = self::FORMAT_MAP[$fmt] ?? null;
            }
            $r->addEndpoint($ep);
        }
        return $r;
    }

    /** Vero se il dataset ha almeno un servizio geografico o un file in formato geografico. */
    private static function hasGeoEndpoint(Record $r): bool
    {
        foreach ($r->endpoints as $e) {
            if (!in_array($e->type, ['download', 'webpage'], true)) {
                return true;   // WMS, WFS, ArcGIS REST, OGC API...
            }
            if ($e->type === 'download' && !in_array($e->format, [null, 'csv', 'xlsx', 'pdf', 'zip'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:string,1:string} [URL di package_search, URL del portale] */
    public static function apiUrl(string $url): array
    {
        $url = rtrim(preg_replace('/[?#].*$/', '', $url), '/');
        if (preg_match('~^(.*?)/api/(?:3/)?action(?:/[a-z_]+)?$~', $url, $m)) {
            return [$m[1] . '/api/3/action/package_search', $m[1]];
        }
        return [$url . '/api/3/action/package_search', $url];
    }

    private static function first(array $values): ?string
    {
        foreach ($values as $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }
        return null;
    }

    private static function lastSegment(?string $v): ?string
    {
        return $v === null ? null : preg_replace('~^.*[/#]~', '', $v);
    }
}
