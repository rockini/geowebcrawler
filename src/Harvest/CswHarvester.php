<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use DOMElement;
use GeoWebCrawler\Http\HttpClient;

/**
 * Raccoglie i record da un catalogo CSW 2.0.2 con GetRecords, pagina per pagina.
 *
 * Parametri della sorgente (campo params):
 *   max_records  numero massimo di record da leggere (default: harvest.max_records in config.php, 25000)
 *   page_size    record per richiesta (default 50)
 *   schema       "auto" (default: prova ISO 19139, se fallisce usa Dublin Core), "iso", "dc"
 *                oppure "dcat" (GeoDCAT-AP / DCAT-AP, offerto per esempio da GeoNetwork 4)
 *   constraint   filtro CQL facoltativo, es. "AnyText like '%idrografia%'"
 *   method       "post" o "get" (di default si provano entrambi)
 *   debug        true: nel log anche l'inizio della risposta del server a ogni tentativo fallito
 */
final class CswHarvester implements Harvester
{
    private const SCHEMA_ISO = 'http://www.isotc211.org/2005/gmd';
    private const SCHEMA_DC  = 'http://www.opengis.net/cat/csw/2.0.2';
    private const SCHEMA_DCAT = 'http://www.w3.org/ns/dcat#';

    /** Descrizione dell'ultima richiesta GetRecords (URL completo per GET, URL e corpo per POST). */
    private string $lastRequest = '';

    public function __construct(
        private HttpClient $http = new HttpClient(),
        private Iso19139Parser $iso = new Iso19139Parser(),
        private DublinCoreParser $dc = new DublinCoreParser(),
        private Rdf\DcatMapper $dcat = new Rdf\DcatMapper(),
    ) {}

    public function harvest(array $source, callable $log): \Generator
    {
        $p        = $source['params'] ?? [];
        // l'indirizzo del catalogo senza i parametri OGC (es. ?SERVICE=CSW&REQUEST=GetCapabilities
        // copiato dal browser): altrimenti il server riceve due richieste diverse insieme
        $url      = self::endpointUrl($source['url']);
        $max      = max(1, (int) ($p['max_records'] ?? \GeoWebCrawler\Config::get('harvest.max_records', 25000)));
        $pageSize = max(1, min(500, (int) ($p['page_size'] ?? 50)));
        $schema   = $p['schema'] ?? 'auto';
        $method   = strtolower($p['method'] ?? 'post');
        $cql      = $p['constraint'] ?? null;

        $useDcat = $schema === 'dcat';
        $useIso = !$useDcat && $schema !== 'dc';
        $forced = isset($p['method']);
        // varianti della richiesta da provare in ordine se il catalogo risponde con un errore:
        // typeNames "csw:Record" e' la forma piu' compatibile (la usano GeoNetwork, OWSLib, QGIS)
        // Alcuni GeoNetwork 4 vanno in NullPointerException se la richiesta non ha un filtro:
        // in quel caso si riprova con un filtro che include tutto (AnyText like '%').
        $attempts = function (bool $iso) use ($forced, $method, $cql): array {
            $all = [];
            foreach (['post', 'get'] as $m) {
                foreach (array_filter(['csw:Record', $iso ? 'gmd:MD_Metadata' : null]) as $t) {
                    foreach ($cql ? [false] : [false, true] as $matchAll) {
                        $all[] = [$m, $t, $matchAll];
                    }
                }
            }
            return array_values(array_filter($all, fn($a) => !$forced || $a[0] === $method));
        };
        $variants = $attempts($useIso);
        [$method, $typeNames, $matchAll] = $variants[0];
        $start = 1;
        $read = 0;
        $errors = [];
        $debug = !empty($p['debug']);

        // Come farebbe un browser: prima GetCapabilities (verifica il servizio e apre la sessione,
        // che alcuni GeoNetwork richiedono), poi GetRecords
        $caps = $this->http->get($url . (str_contains($url, '?') ? '&' : '?') . 'service=CSW&version=2.0.2&request=GetCapabilities');
        if (!$caps->ok()) {
            $log('GetCapabilities non risponde (' . $caps->problem() . '): provo comunque GetRecords');
        }

        while ($read < $max) {
            $n = min($pageSize, $max - $read);
            $xml = $this->getRecords($url, $method, $useDcat ? self::SCHEMA_DCAT : ($useIso ? self::SCHEMA_ISO : self::SCHEMA_DC),
                $typeNames, $start, $n, $cql, $matchAll, $log);
            $doc = $xml !== null ? Xml::load($xml) : null;
            $error = $doc === null ? 'risposta non XML' : $this->exception($doc);

            if ($error !== null) {
                if ($start > 1) {
                    throw new \RuntimeException("Errore dal catalogo CSW a partire dal record $start: $error");
                }
                $errors[] = sprintf('%s %s %s%s: %s', $useIso ? 'ISO' : 'DC', strtoupper($method), $typeNames,
                    $matchAll ? ' con filtro' : '', mb_substr($error, 0, 200));
                // dettaglio della richiesta, per poterla ripetere a mano (browser o curl)
                $log('Tentativo fallito: ' . end($errors) . "\n    " . $this->lastRequest
                    . ($debug ? "\n    Risposta: " . mb_substr((string) $xml, 0, 1000) : ''));
                array_shift($variants);
                if (!$variants && $useIso && $schema === 'auto') {
                    $log('ISO 19139 non disponibile, riprovo con Dublin Core. Tentativi: ' . implode(' ; ', $errors));
                    $useIso = false;
                    $variants = $attempts(false);
                    $errors = [];
                }
                if (!$variants) {
                    throw new \RuntimeException('Errore dal catalogo CSW. Tentativi: ' . implode(' ; ', $errors));
                }
                [$method, $typeNames, $matchAll] = $variants[0];
                continue;
            }
            if ($start === 1 && $errors) {
                $log('Richiesta accettata nella forma ' . strtoupper($method) . " typeNames=$typeNames"
                    . ($matchAll ? ' con filtro AnyText' : '') . ' dopo: ' . implode(' ; ', $errors));
            }

            $xp = Xml::xpath($doc);
            $results = $xp->query('//csw:SearchResults')->item(0);
            if (!$results instanceof DOMElement) {
                throw new \RuntimeException('Risposta CSW senza SearchResults');
            }
            $matched = (int) $results->getAttribute('numberOfRecordsMatched');
            $next    = (int) $results->getAttribute('nextRecord');
            if ($start === 1) {
                $log(sprintf('Record disponibili nel catalogo: %d (ne leggo al massimo %d, formato %s)',
                    $matched, $max, $useDcat ? 'GeoDCAT-AP' : ($useIso ? 'ISO 19139' : 'Dublin Core')));
            }

            if ($useDcat) {
                // ogni record e' un documento rdf:RDF (o un nodo DCAT): si legge come grafo RDF
                $records = 0;
                foreach ($results->childNodes as $child) {
                    if (!$child instanceof DOMElement) {
                        continue;
                    }
                    $records++;
                    $graph = (new Rdf\RdfXmlParser())->parse($child);
                    foreach ($this->dcat->records($graph, 'GeoDCAT-AP (CSW)') as $record) {
                        $record->metadataUrl ??= $this->recordByIdUrl($url, $record->externalId, self::SCHEMA_DCAT);
                        yield $record;
                    }
                }
                $read += $records;
                if ($records === 0 || $next <= 0 || ($matched > 0 && $next > $matched) || $next <= $start) {
                    break;
                }
                $start = $next;
                continue;
            }

            $nodes = $useIso
                ? $xp->query('gmd:MD_Metadata', $results)
                : $xp->query('csw:Record | csw:SummaryRecord | csw:BriefRecord', $results);
            if ($nodes->length === 0) {
                if ($start === 1 && $useIso && $schema === 'auto' && $matched > 0) {
                    $log('Nessun record ISO nella risposta: riprovo con Dublin Core');
                    $useIso = false;
                    [$method, $typeNames, $matchAll] = $attempts(false)[0];
                    continue;
                }
                break;
            }

            foreach ($nodes as $node) {
                $read++;
                try {
                    $record = $useIso ? $this->iso->parse($xp, $node) : $this->dc->parse($xp, $node);
                } catch (\Throwable $e) {
                    // un record con metadati anomali non deve fermare la raccolta
                    $log("Record #$read non leggibile: " . $e->getMessage());
                    continue;
                }
                if ($record === null) {
                    $log("Record #$read ignorato: titolo mancante");
                    continue;
                }
                $record->metadataUrl = $this->recordByIdUrl($url, $record->externalId, $useIso ? self::SCHEMA_ISO : self::SCHEMA_DC);
                yield $record;
            }

            if ($next <= 0 || ($matched > 0 && $next > $matched) || $next <= $start) {
                break;
            }
            $start = $next;
        }
    }

    private function getRecords(string $url, string $method, string $schema, string $typeNames, int $start, int $n,
                                ?string $cql, bool $matchAll, callable $log): ?string
    {
        if ($matchAll && !$cql) {
            $cql = "AnyText like '%'";
        }

        if ($method === 'get') {
            $q = [
                'service' => 'CSW', 'version' => '2.0.2', 'request' => 'GetRecords',
                'typeNames' => $typeNames, 'resultType' => 'results', 'elementSetName' => 'full',
                'outputSchema' => $schema, 'startPosition' => $start, 'maxRecords' => $n,
            ];
            // il parametro namespace serve solo per il prefisso gmd: (alcuni server non lo gradiscono)
            if (str_starts_with($typeNames, 'gmd:')) {
                $q['namespace'] = 'xmlns(gmd=' . self::SCHEMA_ISO . ')';
            }
            if ($cql) {
                $q += ['constraintLanguage' => 'CQL_TEXT', 'constraint_language_version' => '1.1.0', 'constraint' => $cql];
            }
            // codifica RFC 3986 (spazi come %20), identica a un indirizzo scritto nel browser
            $full = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
            $this->lastRequest = "GET $full";
            $res = $this->http->get($full);
        } else {
            if ($matchAll) {
                // nel POST si usa un filtro OGC, supportato da tutti i cataloghi
                $constraint = '<csw:Constraint version="1.1.0"><ogc:Filter xmlns:ogc="http://www.opengis.net/ogc">'
                    . '<ogc:PropertyIsLike wildCard="%" singleChar="_" escapeChar="\\"><ogc:PropertyName>AnyText</ogc:PropertyName>'
                    . '<ogc:Literal>%</ogc:Literal></ogc:PropertyIsLike></ogc:Filter></csw:Constraint>';
            } else {
                $constraint = $cql
                    ? '<csw:Constraint version="1.1.0"><csw:CqlText>' . htmlspecialchars($cql, ENT_XML1) . '</csw:CqlText></csw:Constraint>'
                    : '';
            }
            $body = <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <csw:GetRecords xmlns:csw="http://www.opengis.net/cat/csw/2.0.2" xmlns:gmd="http://www.isotc211.org/2005/gmd"
                    service="CSW" version="2.0.2" resultType="results" startPosition="$start" maxRecords="$n"
                    outputFormat="application/xml" outputSchema="$schema">
                  <csw:Query typeNames="$typeNames">
                    <csw:ElementSetName>full</csw:ElementSetName>$constraint
                  </csw:Query>
                </csw:GetRecords>
                XML;
            $this->lastRequest = "POST $url\n    " . preg_replace('/\s+/', ' ', $body);
            $res = $this->http->post($url, $body);
        }

        if (!$res->ok() && $res->body === '') {
            $log('Richiesta fallita: ' . $res->problem());
            return null;
        }
        return $res->body;
    }

    /** URL del catalogo senza i parametri OGC (service, request, version...), ma con quelli propri del server. */
    public static function endpointUrl(string $url): string
    {
        $url = trim(preg_replace('/#.*$/', '', $url));
        $parts = explode('?', $url, 2);
        if (count($parts) < 2) {
            return $url;
        }
        $ogc = ['service', 'request', 'version', 'acceptversions', 'outputschema', 'typenames', 'elementsetname',
                'resulttype', 'startposition', 'maxrecords', 'namespace', 'id', 'outputformat'];
        $keep = array_filter(explode('&', $parts[1]),
            fn($kv) => $kv !== '' && !in_array(strtolower(urldecode(explode('=', $kv, 2)[0])), $ogc, true));
        return $parts[0] . ($keep ? '?' . implode('&', $keep) : '');
    }

    /** Testo di un eventuale ows:ExceptionReport, altrimenti null. */
    private function exception(\DOMDocument $doc): ?string
    {
        $root = $doc->documentElement;
        if ($root && $root->localName === 'ExceptionReport') {
            return trim(preg_replace('/\s+/', ' ', $root->textContent)) ?: 'ExceptionReport';
        }
        return null;
    }

    private function recordByIdUrl(string $url, ?string $id, string $schema): ?string
    {
        if ($id === null) {
            return null;
        }
        $base = preg_replace('/\?.*$/', '', $url);
        return $base . '?' . http_build_query([
            'service' => 'CSW', 'version' => '2.0.2', 'request' => 'GetRecordById',
            'id' => $id, 'elementSetName' => 'full', 'outputSchema' => $schema,
        ]);
    }
}
