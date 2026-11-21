<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest\Rdf;

use GeoWebCrawler\Harvest\Endpoint;
use GeoWebCrawler\Harvest\EndpointClassifier;
use GeoWebCrawler\Harvest\Geo;
use GeoWebCrawler\Harvest\Record;
use GeoWebCrawler\Harvest\Xml;

/**
 * Converte i dcat:Dataset (e dcat:DataService) di un grafo DCAT-AP / GeoDCAT-AP / DCAT-AP_IT in Record.
 */
final class DcatMapper
{
    private const G = Graph::class;

    public function __construct(private EndpointClassifier $classifier = new EndpointClassifier()) {}

    /** @return \Generator<Record> */
    public function records(Graph $g, string $standard = 'DCAT-AP'): \Generator
    {
        foreach ($g->subjectsOfType(Graph::DCAT . 'Dataset') as $s) {
            if ($r = $this->dataset($g, $s, $standard)) {
                yield $r;
            }
        }
        // servizi gia' descritti come dcat:accessService di una distribuzione: non vanno ripetuti
        $used = [];
        foreach ($g->triples as $props) {
            foreach ($props[Graph::DCAT . 'accessService'] ?? [] as $o) {
                if (isset($o['uri'])) {
                    $used[$o['uri']] = true;
                }
            }
        }
        foreach ($g->subjectsOfType(Graph::DCAT . 'DataService') as $s) {
            if (!isset($used[$s]) && !$g->hasType($s, Graph::DCAT . 'Dataset') && ($r = $this->dataset($g, $s, $standard))) {
                $r->resourceType = 'service';
                yield $r;
            }
        }
    }

    /** Pagina successiva (paginazione Hydra usata da CKAN/ckanext-dcat e da altri). */
    public static function nextPage(Graph $g): ?string
    {
        foreach ($g->triples as $s => $props) {
            foreach ([Graph::HYDRA . 'nextPage', Graph::HYDRA . 'next'] as $p) {
                if ($v = $g->value($s, $p)) {
                    return $v;
                }
            }
        }
        return null;
    }

    public function dataset(Graph $g, string $s, string $standard): ?Record
    {
        $title = $g->literal($s, Graph::DCT . 'title');
        if ($title === null) {
            return null;
        }
        $isUri = !str_starts_with($s, '_:');
        $id = $g->value($s, Graph::DCT . 'identifier') ?? ($isUri ? $s : null);
        $r = new Record($id ?? md5($title), $title);
        $r->externalId = $id;
        $r->abstract = $g->literal($s, Graph::DCT . 'description');
        $r->keywords = $g->literals($s, Graph::DCAT . 'keyword');
        $r->topicCategories = array_map([self::class, 'lastSegment'],
            array_merge($g->uris($s, Graph::DCAT . 'theme'), $g->uris($s, Graph::DCT . 'subject')));
        $r->language = self::lastSegment($g->value($s, Graph::DCT . 'language'));
        $r->datePublished = Xml::date($g->value($s, Graph::DCT . 'issued'));
        $r->dateRevised = Xml::date($g->value($s, Graph::DCT . 'modified'));
        $r->updateFrequency = self::lastSegment($g->value($s, Graph::DCT . 'accrualPeriodicity'));
        $r->metadataUrl = $g->value($s, Graph::DCAT . 'landingPage') ?? ($isUri && preg_match('~^https?://~', $s) ? $s : null);

        $type = strtolower((string) $g->value($s, Graph::DCT . 'type'));
        $r->resourceType = str_contains($type, 'series') ? 'series' : (str_contains($type, 'service') ? 'service' : 'dataset');

        // enti
        foreach ([[Graph::DCT . 'creator', 'author'], [Graph::DCT . 'publisher', 'publisher'],
                  [Graph::DCT . 'rightsHolder', 'owner'], [Graph::DCAT . 'contactPoint', 'pointOfContact']] as [$p, $role]) {
            foreach ($g->objects($s, $p) as $o) {
                if (isset($o['uri'])) {
                    $name = $g->label($o['uri']);
                    $email = $g->value($o['uri'], Graph::VCARD . 'hasEmail') ?? $g->value($o['uri'], Graph::FOAF . 'mbox');
                    $url = $g->value($o['uri'], Graph::FOAF . 'homepage') ?? $g->value($o['uri'], Graph::VCARD . 'hasURL');
                    $r->addParty((string) $name, $role, $email ? preg_replace('/^mailto:/i', '', $email) : null, $url);
                } elseif (isset($o['v'])) {
                    $r->addParty($o['v'], $role);
                }
            }
        }

        // estensione geografica (dct:spatial -> dcat:bbox o locn:geometry: WKT, GeoJSON o GML)
        foreach ($g->uris($s, Graph::DCT . 'spatial') as $loc) {
            foreach ([Graph::DCAT . 'bbox', Graph::LOCN . 'geometry'] as $p) {
                foreach ($g->objects($loc, $p) as $o) {
                    if (isset($o['v']) && ($box = self::geometryBox($o['v']))) {
                        $r->bbox = $box;
                        break 3;
                    }
                }
            }
        }

        // sistemi di riferimento (GeoDCAT-AP: dct:conformsTo con URI EPSG)
        $r->crs = Xml::epsg(array_merge($g->uris($s, Graph::DCT . 'conformsTo'),
            array_filter(array_map(fn($u) => $g->label($u), $g->uris($s, Graph::DCT . 'conformsTo')))));

        // licenze e vincoli: dal dataset e dalle distribuzioni
        $lic = [];
        $access = [];
        $distributions = $g->uris($s, Graph::DCAT . 'distribution');
        foreach ([$s, ...$distributions] as $node) {
            foreach ([Graph::DCT . 'license', Graph::DCT . 'rights'] as $p) {
                foreach ($g->objects($node, $p) as $o) {
                    $lic[] = $this->describe($g, $o);
                }
            }
            foreach ($g->objects($node, Graph::DCT . 'accessRights') as $o) {
                $access[] = $this->describe($g, $o);
            }
        }
        $lic = array_values(array_unique(array_filter($lic)));
        $access = array_values(array_unique(array_filter($access)));
        $r->licenseText = $lic ? implode(' | ', $lic) : null;
        $r->accessConstraints = $access ? implode(' | ', $access) : null;

        // punti di accesso
        foreach ($distributions as $d) {
            $fmt = self::formatName($g, $g->objects($d, Graph::DCT . 'format')[0] ?? null)
                ?? self::formatName($g, $g->objects($d, Graph::DCAT . 'mediaType')[0] ?? null);
            $protocol = $g->value($d, Graph::DCT . 'conformsTo');
            $label = $g->literal($d, Graph::DCT . 'title') ?? $g->literal($d, Graph::DCT . 'description');
            foreach ($g->uris($d, Graph::DCAT . 'downloadURL') as $u) {
                $this->addEndpoint($r, $u, $fmt, $protocol, $label, true);
            }
            foreach ($g->uris($d, Graph::DCAT . 'accessURL') as $u) {
                $this->addEndpoint($r, $u, $fmt, $protocol, $label, false);
            }
            foreach ($g->uris($d, Graph::DCAT . 'accessService') as $svc) {
                foreach ($g->uris($svc, Graph::DCAT . 'endpointURL') as $u) {
                    $this->addEndpoint($r, $u, $fmt, $g->value($svc, Graph::DCT . 'conformsTo') ?? $protocol,
                        $g->literal($svc, Graph::DCT . 'title') ?? $label, false);
                }
            }
        }
        // un dcat:DataService dichiara i dataset che pubblica
        $r->servesRefs = array_values(array_unique(array_merge(
            array_filter($g->uris($s, Graph::DCAT . 'servesDataset'), fn($u) => !str_starts_with($u, '_:')),
            array_filter(array_map(fn($u) => $g->value($u, Graph::DCT . 'identifier'), $g->uris($s, Graph::DCAT . 'servesDataset'))),
        )));
        // un dcat:DataService ha l'indirizzo direttamente su di se'
        foreach ($g->uris($s, Graph::DCAT . 'endpointURL') as $u) {
            $this->addEndpoint($r, $u, null, $g->value($s, Graph::DCT . 'conformsTo'), null, false);
        }

        $r->metadataStandard = $standard;
        return $r;
    }

    private function addEndpoint(Record $r, string $url, ?string $fmt, ?string $protocol, ?string $label, bool $download): void
    {
        $proto = $protocol ?? $fmt;
        $ep = $this->classifier->classify($url, $proto, $label);
        if ($ep === null) {
            return;
        }
        if ($download && $ep->type === 'webpage') {
            $ep->type = 'download';
        }
        if ($ep->type === 'download' && $ep->format === null) {
            $ep->format = EndpointClassifier::formatCode($fmt);
        }
        foreach ($r->endpoints as $e) {
            if ($e->url === $ep->url && $e->layerName === $ep->layerName) {
                return;   // stesso link indicato come accessURL e downloadURL
            }
        }
        $r->addEndpoint($ep);
    }

    /** Testo che descrive un oggetto: letterale, etichetta del nodo e/o URI. */
    private function describe(Graph $g, ?array $o): ?string
    {
        if ($o === null) {
            return null;
        }
        if (isset($o['v'])) {
            return trim($o['v']) ?: null;
        }
        $uri = $o['uri'];
        $label = $g->label($uri);
        if (str_starts_with($uri, '_:')) {
            return $label;
        }
        return $label ? "$label ($uri)" : $uri;
    }

    /** Nome del formato: letterale, ultima parte dell'URI (es. .../file-type/SHP) o etichetta del nodo. */
    private static function formatName(Graph $g, ?array $o): ?string
    {
        if ($o === null) {
            return null;
        }
        if (isset($o['v'])) {
            return trim($o['v']) ?: null;
        }
        return str_starts_with($o['uri'], '_:') ? $g->label($o['uri']) : self::lastSegment($o['uri']);
    }

    private static function geometryBox(string $v): ?array
    {
        $v = trim($v);
        if (str_starts_with($v, '{')) {
            $j = json_decode($v, true);
            return is_array($j) ? Geo::bboxOfGeoJson($j) : null;
        }
        if (str_starts_with($v, '<')) {
            return Geo::bboxOfGml($v);
        }
        return Geo::bboxOfWkt($v);
    }

    public static function lastSegment(?string $v): ?string
    {
        return $v === null ? null : preg_replace('~^.*[/#]~', '', rtrim($v, '/'));
    }
}
