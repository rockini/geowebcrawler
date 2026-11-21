<?php
declare(strict_types=1);

namespace GeoWebCrawler\Check;

use GeoWebCrawler\Config;
use GeoWebCrawler\Harvest\OgcCapabilitiesHarvester as Ogc;
use GeoWebCrawler\Harvest\Xml;
use GeoWebCrawler\Http\HttpClient;

/**
 * Esegue le richieste di verifica in parallelo (curl_multi), con un limite di richieste contemporanee
 * in totale e per singolo server, e ne valuta l'esito.
 *
 *  - Servizi OGC: GetCapabilities. Ok se arriva un documento Capabilities valido; errore se arriva
 *    un'eccezione OGC, una pagina HTML o un errore HTTP. Si leggono titolo, versione e nomi dei layer.
 *  - Altri indirizzi: richiesta HEAD (se il server non la accetta, GET dei primi byte).
 *    Avviso se un file atteso (zip, shp, gpkg...) risponde con una pagina HTML o se serve un accesso.
 */
final class ServiceChecker
{
    public const OGC = ['wms', 'wfs', 'wcs', 'wmts', 'csw', 'sos'];
    private const FILE_EXT = '/\.(zip|shp|gpkg|geojson|json|kml|kmz|gml|tif|tiff|csv|xlsx?|7z|rar|gz|dxf|dwg|ecw|jp2|las|laz|xyz|asc|sqlite|pdf)$/i';

    private int $concurrency;
    private int $perHost;
    private int $timeout;
    private int $slowMs;
    private string $ua;

    public function __construct(array $options = [])
    {
        $this->concurrency = max(1, (int) ($options['concurrency'] ?? Config::get('check.concurrency', 8)));
        $this->perHost = max(1, (int) ($options['per_host'] ?? Config::get('check.per_host', 2)));
        $this->timeout = max(5, (int) ($options['timeout'] ?? Config::get('check.timeout', 30)));
        $this->slowMs = max(1000, (int) ($options['slow_ms'] ?? Config::get('check.slow_ms', 15000)));
        $ua = (string) Config::get('http.user_agent', HttpClient::DEFAULT_UA);
        $this->ua = preg_match('/crawl|bot\b|spider/i', $ua) ? HttpClient::DEFAULT_UA : $ua;
    }

    /** Indirizzo che verra' chiamato per un obiettivo. */
    public static function requestUrl(string $kind, string $url): string
    {
        if (in_array($kind, self::OGC, true)) {
            return Ogc::capabilitiesUrl(Ogc::baseUrl($url), strtoupper($kind), $url);
        }
        return $url;
    }

    /**
     * @param array<int, array{key:string, kind:string, url:string}> $jobs
     * @param callable(string $key, array $result):void $onResult chiamata appena un obiettivo e' verificato
     */
    public function run(array $jobs, callable $onResult, ?callable $shouldStop = null): void
    {
        $queue = [];
        foreach ($jobs as $j) {
            $queue[] = $j + ['phase' => in_array($j['kind'], self::OGC, true) ? 'caps' : 'head'];
        }
        $mh = curl_multi_init();
        $active = [];      // id handle => [job, stato]
        $perHost = [];
        $stopped = false;

        while ($queue || $active) {
            // riempie i posti liberi, rispettando il limite per server
            if (!$stopped) {
                foreach ($queue as $i => $job) {
                    if (count($active) >= $this->concurrency) {
                        break;
                    }
                    $host = strtolower((string) parse_url($job['url'], PHP_URL_HOST));
                    if (($perHost[$host] ?? 0) >= $this->perHost) {
                        continue;
                    }
                    $state = new \stdClass();
                    $ch = $this->handle($job, $state);
                    curl_multi_add_handle($mh, $ch);
                    $active[spl_object_id($ch)] = [$ch, $job, $state, $host, microtime(true)];
                    $perHost[$host] = ($perHost[$host] ?? 0) + 1;
                    unset($queue[$i]);
                }
            }
            if (!$active) {
                if ($stopped || !$queue) {
                    break;
                }
                usleep(50000);
                continue;
            }
            do {
                $mrc = curl_multi_exec($mh, $running);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $job, $state, $host, $t0] = $active[spl_object_id($ch)];
                unset($active[spl_object_id($ch)]);
                $perHost[$host]--;
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $res = [
                    'status'   => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    'ms'       => $ms,
                    'final'    => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                    'ctype'    => $state->ctype ?? null,
                    'length'   => $state->length ?? null,
                    'body'     => $state->body ?? '',
                    'error'    => ($info['result'] !== CURLE_OK && empty($state->cut)) ? curl_error($ch) : null,
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                // HEAD non accettata: si riprova con GET dei primi byte
                if ($job['phase'] === 'head' && ($res['error'] !== null || in_array($res['status'], [400, 403, 404, 405, 406, 500, 501], true))) {
                    $job['phase'] = 'get';
                    $job['head_ms'] = $ms;
                    $queue[] = $job;
                    continue;
                }
                $onResult($job['key'], $this->evaluate($job, $res));
            }
            if (!$stopped && $shouldStop && $shouldStop()) {
                $stopped = true;
                $queue = [];
            }
            if ($active) {
                curl_multi_select($mh, 0.5);
            }
        }
        curl_multi_close($mh);
    }

    private function handle(array $job, \stdClass $state)
    {
        $url = $job['phase'] === 'caps' ? self::requestUrl($job['kind'], $job['url']) : $job['url'];
        $state->url = $url;
        $state->body = '';
        $limit = $job['phase'] === 'caps' ? 30 * 1048576 : 65536;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => $this->ua,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: ' . ($job['phase'] === 'caps' ? 'application/xml, text/xml;q=0.9, */*;q=0.5' : '*/*'),
                'Accept-Language: it-IT,it;q=0.9,en;q=0.8',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use ($state): int {
                if (preg_match('~^HTTP/~i', $line)) {
                    $state->ctype = null;      // nuova risposta (dopo un redirect)
                    $state->length = null;
                } elseif (preg_match('/^content-type:\s*(.+)$/i', trim($line), $m)) {
                    $state->ctype = trim($m[1]);
                } elseif (preg_match('/^content-length:\s*(\d+)/i', trim($line), $m)) {
                    $state->length = (int) $m[1];
                } elseif (preg_match('~^content-range:\s*bytes\s+\d+-\d+/(\d+)~i', trim($line), $m)) {
                    $state->length = (int) $m[1];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use ($state, $limit): int {
                $state->body .= $data;
                if (strlen($state->body) >= $limit) {
                    $state->cut = true;        // basta cosi': si interrompe senza considerarlo un errore
                    return 0;
                }
                return strlen($data);
            },
        ];
        if ($job['phase'] === 'head') {
            $opts[CURLOPT_NOBODY] = true;
        } elseif ($job['phase'] === 'get') {
            $opts[CURLOPT_RANGE] = '0-4095';
        }
        if ($ca = Config::get('http.ca_bundle')) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        return $ch;
    }

    /** @return array risultato da salvare */
    private function evaluate(array $job, array $r): array
    {
        $out = [
            'kind' => $job['kind'], 'url' => $job['phase'] === 'caps' ? self::requestUrl($job['kind'], $job['url']) : $job['url'],
            'http_status' => $r['status'] ?: null, 'ms' => $r['ms'] + (int) ($job['head_ms'] ?? 0), 'content_type' => $r['ctype'],
            'content_length' => $r['length'], 'final_url' => $r['final'] ?: null, 'title' => null, 'version' => null,
            'layers' => null, 'status' => 'ok', 'note' => null, 'capabilities_ok' => null,
        ];
        $notes = [];
        if ($r['error'] !== null) {
            $out['status'] = 'error';
            $out['note'] = self::curlNote($r['error']);
            return $out;
        }
        $s = $r['status'];
        if ($s === 401 || $s === 403) {
            $out['status'] = 'warn';
            $out['note'] = "Accesso riservato (HTTP $s): serve un'autorizzazione";
            return $out;
        }
        if ($s === 429) {
            $out['status'] = 'warn';
            $out['note'] = 'Il server rifiuta per troppe richieste (HTTP 429): riprovare piu\' tardi';
            return $out;
        }
        if ($s === 404 || $s === 410) {
            $out['status'] = 'error';
            $out['note'] = "Non trovato (HTTP $s)";
            return $out;
        }
        if ($s >= 500) {
            $out['status'] = 'error';
            $out['note'] = "Errore del server (HTTP $s)";
            return $out;
        }
        if ($s < 200 || $s >= 300) {
            $out['status'] = 'error';
            $out['note'] = $s === 400 ? "Richiesta rifiutata (HTTP 400): forse il servizio non e' di questo tipo o l'indirizzo non e' corretto" : "Risposta HTTP $s";
            return $out;
        }

        if ($job['phase'] === 'caps') {
            $out['capabilities_ok'] = false;
            $doc = Xml::load($r['body']);
            $root = $doc?->documentElement;
            if ($root === null) {
                $out['status'] = 'error';
                $out['note'] = preg_match('/<html/i', substr($r['body'], 0, 2000))
                    ? 'Risponde con una pagina HTML invece del GetCapabilities'
                    : 'Risposta non leggibile come XML' . (!empty($r['body']) ? '' : ' (vuota)');
                return $out;
            }
            $name = $root->localName;
            if (preg_match('/Exception/i', $name)) {
                $txt = trim(preg_replace('/\s+/', ' ', $root->textContent) ?? '');
                $out['status'] = 'error';
                $out['note'] = 'Eccezione OGC: ' . (mb_substr($txt, 0, 300) ?: $name);
                return $out;
            }
            if (!preg_match('/Capabilities$/', $name)) {
                $out['status'] = 'error';
                $out['note'] = "Documento inatteso <$name> invece del GetCapabilities";
                return $out;
            }
            $out['capabilities_ok'] = true;
            $xp = Xml::xpath($doc);
            $out['version'] = $root->getAttribute('version') ?: null;
            $t = $xp->query('//*[local-name()="ServiceIdentification"]/*[local-name()="Title"] | /*/*[local-name()="Service"]/*[local-name()="Title"]');
            $out['title'] = $t && $t->length ? mb_substr(trim($t->item(0)->textContent), 0, 300) : null;
            $q = match ($job['kind']) {
                'wms'  => '//*[local-name()="Layer"]/*[local-name()="Name"]',
                'wfs'  => '//*[local-name()="FeatureType"]/*[local-name()="Name"]',
                'wcs'  => '//*[local-name()="CoverageSummary"]/*[local-name()="CoverageId" or local-name()="Identifier"]'
                        . ' | //*[local-name()="CoverageOfferingBrief"]/*[local-name()="name"]',
                'wmts' => '//*[local-name()="Contents"]/*[local-name()="Layer"]/*[local-name()="Identifier"]',
                default => null,
            };
            if ($q !== null) {
                $layers = [];
                foreach ($xp->query($q) as $n) {
                    $layers[] = trim($n->textContent);
                }
                $out['layers'] = array_values(array_unique(array_filter($layers)));
                if (!$out['layers']) {
                    $notes[] = 'Il GetCapabilities non elenca nessun layer';
                    $out['status'] = 'warn';
                }
            }
            if (!empty($r['body']) && strlen($r['body']) >= 30 * 1048576) {
                $notes[] = 'GetCapabilities molto grande (letto solo in parte)';
            }
        } else {
            $path = (string) parse_url($job['url'], PHP_URL_PATH);
            if (preg_match(self::FILE_EXT, $path) && $r['ctype'] && preg_match('~text/html~i', $r['ctype'])) {
                $out['status'] = 'warn';
                $notes[] = 'Atteso un file, ma risponde una pagina HTML (forse serve un accesso o il link e\' cambiato)';
            }
        }
        if ($out['ms'] > $this->slowMs) {
            $out['status'] = $out['status'] === 'ok' ? 'warn' : $out['status'];
            $notes[] = 'Risposta lenta (' . round($out['ms'] / 1000, 1) . ' s)';
        }
        $out['note'] = $notes ? implode('; ', $notes) : null;
        return $out;
    }

    private static function curlNote(string $e): string
    {
        return match (true) {
            (bool) preg_match('/resolve host/i', $e)              => 'Server inesistente (nome non risolto): ' . $e,
            (bool) preg_match('/timed out|timeout/i', $e)         => 'Nessuna risposta entro il tempo massimo: ' . $e,
            (bool) preg_match('/SSL certificate|certificate/i', $e) => 'Problema con il certificato HTTPS (se succede con tutti i siti, imposta http.ca_bundle in config.php): ' . $e,
            (bool) preg_match('/refused/i', $e)                   => 'Connessione rifiutata: ' . $e,
            (bool) preg_match('/redirect/i', $e)                  => 'Troppi reindirizzamenti: ' . $e,
            default                                               => $e,
        };
    }

    /** Il layer di un punto di accesso e' tra quelli del GetCapabilities? (anche senza prefisso "ns:") */
    public static function layerFound(?string $layer, ?array $layers): ?bool
    {
        if ($layer === null || $layer === '' || $layers === null) {
            return null;
        }
        $set = [];
        foreach ($layers as $l) {
            $l = mb_strtolower($l);
            $set[$l] = true;
            $set[preg_replace('/^[^:]+:/', '', $l)] = true;
        }
        $x = mb_strtolower(trim($layer));
        return isset($set[$x]) || isset($set[preg_replace('/^[^:]+:/', '', $x)]);
    }
}
