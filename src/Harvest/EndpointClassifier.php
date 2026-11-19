<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

/**
 * Riconosce il tipo di un punto di accesso (WMS, WFS, download di un file...)
 * a partire dall'URL e, se c'e', dalla stringa "protocol" dei metadati.
 */
final class EndpointClassifier
{
    /** estensione => codice formato (vedi gwc.formats) */
    private const EXTENSIONS = [
        'shp' => 'shp', 'geojson' => 'geojson', 'json' => 'geojson', 'gpkg' => 'gpkg',
        'gml' => 'gml', 'kml' => 'kml', 'kmz' => 'kmz', 'dxf' => 'dxf',
        'tif' => 'geotiff', 'tiff' => 'geotiff', 'asc' => 'ascii_grid', 'jp2' => 'jp2', 'ecw' => 'ecw',
        'las' => 'las', 'laz' => 'las', 'nc' => 'netcdf',
        'csv' => 'csv', 'xls' => 'xlsx', 'xlsx' => 'xlsx',
        'zip' => 'zip', '7z' => '7z', 'pdf' => 'pdf',
    ];

    /** Estensioni che, da sole, rendono un link interessante (pdf e json esclusi perche' troppo generici). */
    private const GEO_EXTENSIONS = [
        'shp', 'geojson', 'gpkg', 'gml', 'kml', 'kmz', 'dxf', 'tif', 'tiff', 'asc', 'jp2', 'ecw',
        'las', 'laz', 'nc', 'zip', '7z',
    ];

    /**
     * @param bool $strict se true restituisce null per i link che non sembrano dati geografici
     *                     (usato nell'analisi delle pagine web)
     */
    public function classify(string $url, ?string $protocol = null, ?string $label = null,
                             ?string $layer = null, bool $strict = false): ?Endpoint
    {
        $url = trim($url);
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $path  = strtolower($parts['path'] ?? '');
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query = array_change_key_case($query, CASE_LOWER);
        $proto = strtolower((string) $protocol);

        $type = null;
        $version = isset($query['version']) && is_string($query['version']) ? $query['version'] : null;

        // 1) parametro SERVICE= nella query: il segnale piu' affidabile
        $service = isset($query['service']) && is_string($query['service']) ? strtolower($query['service']) : '';
        if (in_array($service, ['wms', 'wfs', 'wcs', 'wmts', 'csw', 'sos'], true)) {
            $type = $service;
        }
        // 2) stringa protocol dei metadati (es. "OGC:WMS-1.3.0-http-get-map")
        if ($type === null && $proto !== '') {
            foreach (['wmts', 'wms', 'wfs', 'wcs', 'csw', 'sos'] as $t) {
                if (preg_match('~\b(ogc:)?' . $t . '\b~', $proto)) {
                    $type = $t;
                    if ($version === null && preg_match('~' . $t . '[-_ ]?(\d+\.\d+(\.\d+)?)~', $proto, $m)) {
                        $version = $m[1];
                    }
                    break;
                }
            }
            if ($type === null && (str_contains($proto, 'ogcapi') || str_contains($proto, 'ogc api'))) {
                $type = str_contains($proto, 'tiles') ? 'ogcapi_tiles' : 'ogcapi_features';
            } elseif ($type === null && (str_contains($proto, 'esri') || str_contains($proto, 'arcgis'))) {
                $type = 'arcgis_rest';
            } elseif ($type === null && str_contains($proto, 'atom')) {
                $type = 'atom';
            }
        }
        // versione del servizio indicata nel protocol, es. "OGC:WMS-1.3.0-http-get-map"
        if ($type !== null && $version === null && $proto !== ''
            && preg_match('~' . preg_quote($type, '~') . '[-_ ]?(\d+\.\d+(\.\d+)?)~', $proto, $m)) {
            $version = $m[1];
        }
        // 3) forma del percorso
        if ($type === null) {
            if (preg_match('~/(arcgis/)?rest/services/~', $path)) {
                $type = 'arcgis_rest';
            } elseif (preg_match('~/collections(/[^/]+(/items)?)?/?$~', $path) && !preg_match('~\.\w{2,5}$~', $path)) {
                $type = 'ogcapi_features';
            } elseif (preg_match('~/(wmts)(/|$)|wmtscapabilities\.xml$~', $path)) {
                $type = 'wmts';
            } elseif (preg_match('~/(wms|wfs|wcs)(/|$|\?)~', $path, $m)) {
                $type = $m[1];
            }
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $format = self::EXTENSIONS[$ext] ?? null;

        // 4) file scaricabile
        if ($type === null) {
            if ($format !== null && (!$strict || in_array($ext, self::GEO_EXTENSIONS, true))) {
                $type = 'download';
            } elseif ($strict) {
                return null;
            } else {
                $type = str_contains($proto, 'download') ? 'download' : 'webpage';
            }
        }

        if ($type !== 'download') {
            $format = $format === 'zip' ? 'zip' : null;
            if (in_array($type, ['wms', 'wfs', 'wcs', 'wmts'], true) && $layer === null) {
                foreach (['layers', 'typename', 'typenames', 'coverage', 'coverageid', 'layer'] as $k) {
                    if (isset($query[$k]) && is_string($query[$k]) && $query[$k] !== '') {
                        $layer = $query[$k];
                        break;
                    }
                }
            }
        }

        return new Endpoint(
            url: $url,
            type: $type,
            format: $format,
            protocol: $protocol !== null && $protocol !== '' ? $protocol : null,
            serviceVersion: $version,
            layerName: $layer !== null && $layer !== '' ? $layer : null,
            label: $label !== null && trim($label) !== '' ? trim($label) : null,
        );
    }
}
