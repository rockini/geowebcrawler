<?php
declare(strict_types=1);

namespace GeoWebCrawler\Http;

use GeoWebCrawler\Config;

/** Piccolo client HTTP basato su cURL. */
final class HttpClient
{
    private int $timeout;
    private string $userAgent;

    public function __construct(?int $timeout = null, ?string $userAgent = null)
    {
        $this->timeout   = $timeout   ?? (int) Config::get('http.timeout', 30);
        $this->userAgent = $userAgent ?? (string) Config::get('http.user_agent', 'GeoWebCrawler/0.1');
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
