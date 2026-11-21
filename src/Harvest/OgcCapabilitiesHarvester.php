<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use DOMElement;
use DOMXPath;
use GeoWebCrawler\Http\HttpClient;

/**
 * Usa il documento GetCapabilities di un servizio OGC come indice:
 * ogni layer (WMS), feature type (WFS), coverage (WCS) o layer WMTS diventa una risorsa,
 * piu' una risorsa per il servizio stesso.
 *
 * Parametri della sorgente (campo params):
 *   services   elenco dei servizi da interrogare, es. ["WMS","WFS"]. Se assente si usa il
 *              parametro SERVICE dell'URL o, in mancanza, si provano WMS, WFS, WCS e WMTS
 *              (utile con GeoServer, che li espone tutti allo stesso indirizzo /ows).
 *   max_records numero massimo di layer per servizio (default: harvest.max_records in config.php, 25000)
 */
final class OgcCapabilitiesHarvester implements Harvester
{
    private const ALL = ['WMS', 'WFS', 'WCS', 'WMTS'];

    public function __construct(private HttpClient $http = new HttpClient()) {}

    public function harvest(array $source, callable $log): \Generator
    {
        $p = $source['params'] ?? [];
        $base = self::baseUrl($source['url']);
        $fromUrl = self::queryParam($source['url'], 'service');
        $services = array_map('strtoupper', (array) ($p['services'] ?? ($fromUrl ? [$fromUrl] : self::ALL)));
        $max = max(1, (int) ($p['max_records'] ?? \GeoWebCrawler\Config::get('harvest.max_records', 25000)));
        $found = 0;
        $complete = true;

        foreach ($services as $svc) {
            if (!in_array($svc, self::ALL, true)) {
                $log("Servizio '$svc' non riconosciuto: ignorato");
                continue;
            }
            $url = self::capabilitiesUrl($base, $svc, $source['url']);
            $res = $this->http->get($url);
            $doc = $res->ok() ? Xml::load($res->body) : null;
            $root = $doc?->documentElement;
            if ($root === null || !preg_match('~Capabilities$~', $root->localName)) {
                $log("$svc: nessun GetCapabilities valido (" . ($res->ok() ? 'risposta non riconosciuta' : $res->problem()) . ')');
                // errore di rete o del server: i layer di questo servizio non vanno considerati rimossi
                $complete = $complete && $res->status !== 0 && $res->status < 500;
                continue;
            }
            $found++;
            $log("$svc " . ($root->getAttribute('version') ?: '?') . ": GetCapabilities letto");
            yield from $this->parse($doc, $svc, $url, $max, $log);
        }
        if ($found === 0) {
            throw new \RuntimeException('Nessun servizio OGC ha risposto a GetCapabilities');
        }
        return $complete;
    }

    /** Analizza un documento GetCapabilities gia' scaricato. */
    public function parse(\DOMDocument $doc, string $svc, string $capsUrl, int $max = 5000, ?callable $log = null): \Generator
    {
        $xp = Xml::xpath($doc);
        $root = $doc->documentElement;
        $version = $root->getAttribute('version') ?: null;
        $svcType = strtolower($svc);

        // ---- dati del servizio (Service per WMS/WFS 1.0/WCS 1.0, ows:ServiceIdentification per gli altri)
        $svcTitle = self::t($xp, $root, ['Service/Title', 'ServiceIdentification/Title', 'Service/label']);
        $svcAbstract = self::t($xp, $root, ['Service/Abstract', 'ServiceIdentification/Abstract', 'Service/description']);
        $org = self::t($xp, $root, [
            'Service/ContactInformation/ContactPersonPrimary/ContactOrganization',
            'ServiceProvider/ProviderName',
            'Service/responsibleParty/organisationName',
        ]);
        $email = self::t($xp, $root, ['Service/ContactInformation/ContactElectronicMailAddress',
                                      'ServiceProvider/ServiceContact/ContactInfo/Address/ElectronicMailAddress']);
        $constraints = array_values(array_filter([
            self::t($xp, $root, ['Service/AccessConstraints', 'ServiceIdentification/AccessConstraints', 'Service/accessConstraints']),
            self::t($xp, $root, ['Service/Fees', 'ServiceIdentification/Fees', 'Service/fees']),
        ], fn($v) => $v !== null && !preg_match('~^(none|nessuno|nessuna)$~i', $v)));
        $license = $constraints ? implode(' | ', $constraints) : null;
        $svcKeywords = self::all($xp, $root, ['Service/KeywordList/Keyword', 'ServiceIdentification/Keywords/Keyword', 'Service/Keywords/Keyword', 'Service/keywords/keyword']);

        $service = new Record('service:' . $svcType, $svcTitle ?? strtoupper($svcType) . ' ' . parse_url($capsUrl, PHP_URL_HOST));
        $service->resourceType = 'service';
        $service->abstract = $svcAbstract;
        $service->keywords = $svcKeywords;
        $service->licenseText = $license;
        $service->metadataStandard = 'OGC ' . strtoupper($svcType) . ($version ? " $version" : '') . ' GetCapabilities';
        $service->metadataUrl = $capsUrl;
        if ($org) {
            $service->addParty($org, 'publisher', $email);
        }
        $service->addEndpoint(new Endpoint($capsUrl, $svcType, null, 'OGC:' . strtoupper($svcType), $version, null, $svcTitle));
        $layers = iterator_to_array($this->layers($xp, $root, $svcType), false);
        // estensione del servizio: unione di quelle dei layer
        $service->bbox = self::union(array_filter(array_map(fn($l) => $l['bbox'], $layers)));
        $service->rawMetadata = null;
        yield $service;

        $n = 0;
        foreach ($layers as $l) {
            if (++$n > $max) {
                $log && $log("Raggiunto il limite di $max layer");
                break;
            }
            $r = new Record($svcType . ':' . $l['name'], $l['title'] ?: $l['name']);
            $r->resourceType = 'dataset';
            $r->abstract = $l['abstract'];
            $r->keywords = $l['keywords'];
            $r->crs = Xml::epsg($l['crs']);
            $r->bbox = $l['bbox'];
            $r->licenseText = $license;
            $r->metadataStandard = $service->metadataStandard;
            $r->metadataUrl = $l['metadataUrl'] ?? $capsUrl;
            if ($org) {
                $r->addParty($org, 'publisher', $email);
            }
            $r->addEndpoint(new Endpoint($capsUrl, $svcType, null, 'OGC:' . strtoupper($svcType), $version, $l['name'], $l['title']));
            yield $r;
        }
    }

    /** @return \Generator<array{name:string,title:?string,abstract:?string,keywords:array,crs:array,bbox:?array,metadataUrl:?string}> */
    private function layers(DOMXPath $xp, DOMElement $root, string $svc): \Generator
    {
        switch ($svc) {
            case 'wms':
                $top = $xp->query(self::path('Capability/Layer'), $root)->item(0);
                if ($top instanceof DOMElement) {
                    yield from $this->wmsLayer($xp, $top, [], null);
                }
                break;

            case 'wfs':
                foreach ($xp->query(self::path('FeatureTypeList/FeatureType'), $root) as $ft) {
                    $name = self::t($xp, $ft, ['Name']);
                    if ($name === null) {
                        continue;
                    }
                    $bbox = self::owsBox($xp, $ft);
                    if ($bbox === null && ($ll = $xp->query(self::path('LatLongBoundingBox'), $ft)->item(0)) instanceof DOMElement) {
                        $bbox = self::attrBox($ll);   // WFS 1.0
                    }
                    yield [
                        'name' => $name, 'title' => self::t($xp, $ft, ['Title']), 'abstract' => self::t($xp, $ft, ['Abstract']),
                        'keywords' => self::keywords($xp, $ft),
                        'crs' => self::all($xp, $ft, ['DefaultCRS', 'DefaultSRS', 'SRS', 'OtherCRS', 'OtherSRS']),
                        'bbox' => $bbox, 'metadataUrl' => self::metadataUrl($xp, $ft),
                    ];
                }
                break;

            case 'wcs':
                // WCS 1.0: CoverageOfferingBrief; WCS 1.1/2.0: CoverageSummary
                foreach ($xp->query('.//*[local-name()="CoverageOfferingBrief" or local-name()="CoverageSummary"]', $root) as $c) {
                    $name = self::t($xp, $c, ['CoverageId', 'Identifier', 'name']);
                    if ($name === null) {
                        continue;
                    }
                    $bbox = self::owsBox($xp, $c);
                    if ($bbox === null) {
                        $pos = self::all($xp, $c, ['lonLatEnvelope/pos']);
                        if (count($pos) === 2) {
                            [$w, $s] = array_map('floatval', preg_split('/\s+/', $pos[0]));
                            [$e, $n] = array_map('floatval', preg_split('/\s+/', $pos[1]));
                            $bbox = Xml::bbox($w, $s, $e, $n);
                        }
                    }
                    yield [
                        'name' => $name, 'title' => self::t($xp, $c, ['Title', 'label']),
                        'abstract' => self::t($xp, $c, ['Abstract', 'description']),
                        'keywords' => self::keywords($xp, $c), 'crs' => self::all($xp, $c, ['SupportedCRS']),
                        'bbox' => $bbox, 'metadataUrl' => self::metadataUrl($xp, $c),
                    ];
                }
                break;

            case 'wmts':
                foreach ($xp->query(self::path('Contents/Layer'), $root) as $l) {
                    $name = self::t($xp, $l, ['Identifier']);
                    if ($name === null) {
                        continue;
                    }
                    yield [
                        'name' => $name, 'title' => self::t($xp, $l, ['Title']), 'abstract' => self::t($xp, $l, ['Abstract']),
                        'keywords' => self::keywords($xp, $l), 'crs' => [], 'bbox' => self::owsBox($xp, $l),
                        'metadataUrl' => self::metadataUrl($xp, $l),
                    ];
                }
                break;
        }
    }

    /** Visita ricorsiva dei layer WMS: CRS ed estensione si ereditano dal layer padre. */
    private function wmsLayer(DOMXPath $xp, DOMElement $layer, array $parentCrs, ?array $parentBox): \Generator
    {
        $crs = array_values(array_unique(array_merge($parentCrs, self::all($xp, $layer, ['CRS', 'SRS']))));
        $box = null;
        if (($g = $xp->query(self::path('EX_GeographicBoundingBox'), $layer)->item(0)) instanceof DOMElement) {
            $box = Xml::bbox(
                Xml::num(self::t($xp, $g, ['westBoundLongitude'])), Xml::num(self::t($xp, $g, ['southBoundLatitude'])),
                Xml::num(self::t($xp, $g, ['eastBoundLongitude'])), Xml::num(self::t($xp, $g, ['northBoundLatitude'])));
        } elseif (($g = $xp->query(self::path('LatLonBoundingBox'), $layer)->item(0)) instanceof DOMElement) {
            $box = self::attrBox($g);   // WMS 1.1.1
        }
        $box ??= $parentBox;

        $name = self::t($xp, $layer, ['Name']);
        if ($name !== null) {
            yield [
                'name' => $name, 'title' => self::t($xp, $layer, ['Title']), 'abstract' => self::t($xp, $layer, ['Abstract']),
                'keywords' => self::all($xp, $layer, ['KeywordList/Keyword']), 'crs' => $crs, 'bbox' => $box,
                'metadataUrl' => self::metadataUrl($xp, $layer),
            ];
        }
        foreach ($xp->query(self::path('Layer'), $layer) as $child) {
            yield from $this->wmsLayer($xp, $child, $crs, $box);
        }
    }

    // ------------------------------------------------------------------ supporto XPath indipendente dai namespace

    /** 'A/B' -> "*[local-name()='A']/*[local-name()='B']" */
    private static function path(string $p): string
    {
        return implode('/', array_map(fn($s) => "*[local-name()='$s']", explode('/', $p)));
    }

    private static function t(DOMXPath $xp, DOMElement $ctx, array $paths): ?string
    {
        foreach ($paths as $p) {
            $v = Xml::text($xp, self::path($p), $ctx);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    private static function all(DOMXPath $xp, DOMElement $ctx, array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $out = array_merge($out, Xml::texts($xp, self::path($p), $ctx));
        }
        return array_values(array_unique($out));
    }

    private static function keywords(DOMXPath $xp, DOMElement $ctx): array
    {
        $kw = self::all($xp, $ctx, ['Keywords/Keyword', 'KeywordList/Keyword', 'keywords/keyword']);
        if (!$kw && ($plain = self::t($xp, $ctx, ['Keywords']))) {
            $kw = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $plain))));   // WFS 1.0
        }
        return $kw;
    }

    private static function metadataUrl(DOMXPath $xp, DOMElement $ctx): ?string
    {
        $n = $xp->query(self::path('MetadataURL') . '/*[local-name()="OnlineResource"]/@*[local-name()="href"] | '
                        . self::path('MetadataURL') . '/@*[local-name()="href"] | ' . self::path('MetadataURL'), $ctx);
        foreach ($n as $node) {
            $v = trim($node->nodeValue ?? '');
            if (preg_match('~^https?://~', $v)) {
                return $v;
            }
        }
        return null;
    }

    /** ows:WGS84BoundingBox (longitudine latitudine) */
    private static function owsBox(DOMXPath $xp, DOMElement $ctx): ?array
    {
        $box = $xp->query(self::path('WGS84BoundingBox'), $ctx)->item(0);
        if (!$box instanceof DOMElement) {
            return null;
        }
        $lc = preg_split('/\s+/', (string) self::t($xp, $box, ['LowerCorner']));
        $uc = preg_split('/\s+/', (string) self::t($xp, $box, ['UpperCorner']));
        if (count($lc) !== 2 || count($uc) !== 2) {
            return null;
        }
        return Xml::bbox(Xml::num($lc[0]), Xml::num($lc[1]), Xml::num($uc[0]), Xml::num($uc[1]));
    }

    private static function attrBox(DOMElement $e): ?array
    {
        return Xml::bbox(Xml::num($e->getAttribute('minx')), Xml::num($e->getAttribute('miny')),
                         Xml::num($e->getAttribute('maxx')), Xml::num($e->getAttribute('maxy')));
    }

    private static function union(array $boxes): ?array
    {
        if (!$boxes) {
            return null;
        }
        return [min(array_column($boxes, 0)), min(array_column($boxes, 1)), max(array_column($boxes, 2)), max(array_column($boxes, 3))];
    }

    // ------------------------------------------------------------------ URL

    /** URL senza i parametri OGC (service, request, version...), ma con quelli specifici del server (es. map=). */
    public static function baseUrl(string $url): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) < 2) {
            return $url;
        }
        $keep = [];
        foreach (explode('&', $parts[1]) as $kv) {
            $k = strtolower(urldecode(explode('=', $kv, 2)[0]));
            if ($kv !== '' && !in_array($k, ['service', 'request', 'version', 'acceptversions', 'outputformat', 'format', 'layers', 'typename', 'typenames'], true)) {
                $keep[] = $kv;
            }
        }
        return $parts[0] . ($keep ? '?' . implode('&', $keep) : '');
    }

    public static function capabilitiesUrl(string $base, string $svc, string $original): string
    {
        // WMTS RESTful: l'indirizzo e' gia' il documento
        if (preg_match('~WMTSCapabilities\.xml$~i', $original)) {
            return $original;
        }
        return $base . (str_contains($base, '?') ? '&' : '?') . 'SERVICE=' . $svc . '&REQUEST=GetCapabilities';
    }

    private static function queryParam(string $url, string $name): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $q = array_change_key_case($q, CASE_LOWER);
        return isset($q[$name]) && is_string($q[$name]) && $q[$name] !== '' ? $q[$name] : null;
    }
}
