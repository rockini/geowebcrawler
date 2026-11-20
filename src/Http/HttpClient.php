<?php
declare(strict_types=1);

namespace GeoWebCrawler\Http;

use GeoWebCrawler\Config;

/** Piccolo client HTTP basato su cURL. */
final class HttpClient
{
    // Senza "crawler", "bot" o "spider": GeoNetwork tratta questi client come motori di ricerca
    // (niente sessione) e risponde alle richieste CSW con NullPointerException.
    public const DEFAULT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GWC/0.1';

    private int $timeout;
    private string $userAgent;
    private ?string $cookieFile = null;

    public function __construct(?int $timeout = null, ?string $userAgent = null)
    {
        $this->timeout   = $timeout   ?? (int) Config::get('http.timeout', 30);
        $this->userAgent = $userAgent ?? (string) Config::get('http.user_agent', self::DEFAULT_UA);
        if (preg_match('/crawl|bot\b|spider/i', $this->userAgent)) {
            $this->userAgent = self::DEFAULT_UA;   // vedi sopra: alcuni server rifiutano questi nomi
        }
    }

    public function __destruct()
    {
        if ($this->cookieFile !== null && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, string $body, string $contentType = 'application/xml'): HttpResponse
    {
        return $this->request('POST', $url, $body, ['Content-Type: ' . $contentType]);
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        // Intestazioni come quelle di un browser: alcuni server (es. GeoNetwork) le usano
        // per scegliere lingua e formato della risposta
        $names = array_map(fn($h) => strtolower(trim(explode(':', $h, 2)[0])), $headers);
        if (!in_array('accept', $names, true)) {
            $headers[] = 'Accept: application/xml, text/xml;q=0.9, application/json;q=0.9, text/html;q=0.8, */*;q=0.7';
        }
        if (!in_array('accept-language', $names, true)) {
            $headers[] = 'Accept-Language: it-IT,it;q=0.9,en;q=0.8';
        }
        // i cookie (es. la sessione JSESSIONID) restano validi per tutte le richieste di questo client,
        // come in un browser
        $this->cookieFile ??= (tempnam(sys_get_temp_dir(), 'gwc') ?: null);

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => '',          // accetta gzip/deflate
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        // Su Windows PHP spesso non ha i certificati CA: si possono indicare in config.php
        if ($ca = Config::get('http.ca_bundle')) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($this->cookieFile !== null) {
            $opts[CURLOPT_COOKIEFILE] = $this->cookieFile;
            $opts[CURLOPT_COOKIEJAR] = $this->cookieFile;
        }
        curl_setopt_array($ch, $opts);

        $start = microtime(true);
        $content = curl_exec($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $response = new HttpResponse(
            status:      (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            body:        $content === false ? '' : $content,
            contentType: curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null,
            finalUrl:    (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            timeMs:      $ms,
            error:       $content === false ? curl_error($ch) : null,
        );
        return $response;
    }
}
