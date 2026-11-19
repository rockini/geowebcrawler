<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GeoWebCrawler\Http\HttpClient;

/**
 * Analizza una pagina web e ne estrae i link a servizi OGC e a file di dati geografici.
 * Trova sia i link <a href> sia gli URL scritti come testo nella pagina.
 *
 * Parametri della sorgente (campo params):
 *   depth       quanti livelli di link seguire sullo stesso sito (default 0 = solo la pagina)
 *   max_pages   numero massimo di pagine da visitare (default 20)
 *   include     espressione regolare: segui solo le pagine il cui URL la soddisfa
 *   same_host   true (default): segue solo link dello stesso sito
 */
final class WebPageHarvester implements Harvester
{
    private const SKIP_EXT = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'css', 'js', 'ico', 'mp4', 'mp3',
                              'doc', 'docx', 'ppt', 'pptx', 'odt', 'woff', 'woff2', 'ttf'];

    public function __construct(
        private HttpClient $http = new HttpClient(),
        private EndpointClassifier $classifier = new EndpointClassifier(),
    ) {}

    public function harvest(array $source, callable $log): \Generator
    {
        $p        = $source['params'] ?? [];
        $depth    = max(0, (int) ($p['depth'] ?? 0));
        $maxPages = max(1, (int) ($p['max_pages'] ?? 20));
        $include  = $p['include'] ?? null;
        $sameHost = (bool) ($p['same_host'] ?? true);
        $rootHost = strtolower((string) parse_url($source['url'], PHP_URL_HOST));

        $queue   = [[$source['url'], 0]];
        $visited = [];
        $found   = [];
        $pages   = 0;

        while ($queue && $pages < $maxPages) {
            [$pageUrl, $level] = array_shift($queue);
            $key = self::key($pageUrl);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;

            $res = $this->http->get($pageUrl, ['Accept: text/html,application/xhtml+xml']);
            $pages++;
            if (!$res->ok()) {
                $log("Pagina non raggiungibile ($pageUrl): " . $res->problem());
                if ($pages === 1) {
                    throw new \RuntimeException('Pagina iniziale non raggiungibile: ' . $res->problem());
                }
                continue;
            }
            if ($res->contentType && !preg_match('~html|xml~i', $res->contentType)) {
                $log("Ignorata (non e' una pagina HTML): $pageUrl");
                continue;
            }

            [$links, $pageTitle] = $this->extractLinks($res->body, $res->finalUrl ?: $pageUrl);
            $n = 0;
            foreach ($links as [$url, $text]) {
                $ep = $this->classifier->classify($url, null, $text, null, strict: true);
                if ($ep !== null) {
                    $k = self::key($url);
                    if (!isset($found[$k])) {
                        $found[$k] = true;
                        $n++;
                        yield $this->toRecord($ep, $text, $pageUrl, $pageTitle);
                    }
                    continue;
                }
                // pagina da visitare al livello successivo?
                if ($level < $depth && $this->isFollowable($url, $rootHost, $sameHost, $include)) {
                    $queue[] = [$url, $level + 1];
                }
            }
            $log("Pagina analizzata: $pageUrl ($n riferimenti a dati)");
        }
    }

    /** @return array{0: array<int, array{0:string,1:?string}>, 1: ?string} link (url, testo) e titolo della pagina */
    public function extractLinks(string $html, string $baseUrl): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // forza la lettura in UTF-8 se la pagina non dichiara la codifica
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $base = $baseUrl;
        $baseEl = $xp->query('//base[@href]')->item(0);
        if ($baseEl instanceof DOMElement) {
            $base = self::resolve($baseUrl, $baseEl->getAttribute('href')) ?? $baseUrl;
        }
        $title = trim((string) $xp->query('//title')->item(0)?->textContent) ?: null;

        $links = [];
        foreach ($xp->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $url = self::resolve($base, $a->getAttribute('href'));
            if ($url !== null) {
                $text = trim(preg_replace('/\s+/u', ' ', $a->textContent));
                $links[] = [$url, $text !== '' ? $text : ($a->getAttribute('title') ?: null)];
            }
        }
        // URL scritti come testo (es. "Servizio WMS: https://.../wms?service=WMS")
        $body = $xp->query('//body')->item(0)?->textContent ?? '';
        if (preg_match_all('~https?://[^\s<>"\'()\[\]]+~i', $body, $m)) {
            foreach ($m[0] as $u) {
                $links[] = [rtrim($u, '.,;:'), null];
            }
        }
        return [$links, $title];
    }

    private function toRecord(Endpoint $ep, ?string $text, string $pageUrl, ?string $pageTitle): Record
    {
        $isService = !in_array($ep->type, ['download', 'webpage'], true);
        $title = $text;
        if ($title === null || mb_strlen($title) < 4 || preg_match('~^(download|scarica|link|qui|here|wms|wfs|wcs)$~i', $title)) {
            $file = basename((string) parse_url($ep->url, PHP_URL_PATH));
            $host = (string) parse_url($ep->url, PHP_URL_HOST);
            $title = $isService
                ? strtoupper($ep->type) . ' ' . $host . ($ep->layerName ? " - {$ep->layerName}" : '')
                : ($file !== '' ? urldecode($file) : $host);
            if ($text !== null && $text !== '' && strcasecmp($text, $title) !== 0) {
                $title .= " ($text)";
            }
        }
        $r = new Record($ep->url, mb_substr($title, 0, 500));
        $r->resourceType = $isService ? 'service' : 'dataset';
        $r->metadataStandard = 'HTML';
        $r->metadataUrl = $pageUrl;
        $r->abstract = $pageTitle !== null ? "Trovato nella pagina: $pageTitle" : null;
        $r->addEndpoint($ep);
        return $r;
    }

    private function isFollowable(string $url, string $rootHost, bool $sameHost, ?string $include): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($sameHost && $host !== $rootHost) {
            return false;
        }
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($ext, self::SKIP_EXT, true) || in_array($ext, ['pdf', 'zip', 'xml', 'json', 'csv'], true)) {
            return false;
        }
        return $include === null || @preg_match('~' . str_replace('~', '\~', $include) . '~i', $url) === 1;
    }

    /** Risolve un link relativo rispetto all'URL della pagina. Restituisce null per link non http(s). */
    public static function resolve(string $base, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || $href[0] === '#' || preg_match('~^(mailto|javascript|tel|data):~i', $href)) {
            return null;
        }
        $href = preg_replace('/#.*$/', '', $href);
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $scheme = $b['scheme'] ?? 'https';
        $auth = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if ($href[0] === '?') {
            return $auth . ($b['path'] ?? '/') . $href;
        }
        if ($href[0] === '/') {
            $path = $href;
        } else {
            $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
            $path = $dir . $href;
        }
        // risolve ./ e ../
        [$p, $q] = array_pad(explode('?', $path, 2), 2, null);
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $p = implode('/', $out);
        if ($p === '' || $p[0] !== '/') {
            $p = '/' . $p;
        }
        return $auth . $p . ($q !== null ? '?' . $q : '');
    }

    private static function key(string $url): string
    {
        return strtolower(preg_replace('~^https?://(www\.)?|/+$~i', '', $url));
    }
}
