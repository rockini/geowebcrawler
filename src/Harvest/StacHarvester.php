<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use GeoWebCrawler\Http\HttpClient;

/**
 * Raccoglie le collezioni di un catalogo STAC (SpatioTemporal Asset Catalog).
 * Funziona sia con una STAC API (pagina iniziale con /collections) sia con un catalogo
 * statico (file catalog.json con link "child"). Ogni collezione diventa una risorsa;
 * i singoli item (scene, tile) non vengono importati, perche' possono essere milioni.
 *
 * Parametri della sorgente (campo params):
 *   max_records  numero massimo di collezioni (default: harvest.max_records in config.php, 25000)
 *   max_depth    profondita' massima nei cataloghi statici (default 4)
 *   max_requests numero massimo di richieste nei cataloghi statici (default 300)
 */
final class StacHarvester implements Harvester
{
    private const ROLES = ['producer' => 'author', 'licensor' => 'owner', 'host' => 'distributor', 'processor' => 'other'];

    public function __construct(
        private HttpClient $http = new HttpClient(),
        private EndpointClassifier $classifier = new EndpointClassifier(),
    ) {}

    public function harvest(array $source, callable $log): \Generator
    {
        $p = $source['params'] ?? [];
        $max = max(1, (int) ($p['max_records'] ?? \GeoWebCrawler\Config::get('harvest.max_records', 25000)));
        $maxDepth = max(0, (int) ($p['max_depth'] ?? 4));
        $maxRequests = max(1, (int) ($p['max_requests'] ?? 300));

        $rootUrl = $source['url'];
        $root = $this->getJson($rootUrl);
        if (!isset($root['type']) && !isset($root['stac_version']) && !isset($root['collections'])) {
            throw new \RuntimeException('La risposta non sembra un catalogo STAC');
        }

        $read = 0;
        if (($root['type'] ?? '') === 'Collection') {
            yield $this->toRecord($root, $rootUrl);
            return;
        }

        // STAC API: link "data" o elenco /collections
        $collectionsUrl = self::link($root, 'data', $rootUrl);
        if ($collectionsUrl === null && isset($root['conformsTo'])) {
            $collectionsUrl = rtrim($rootUrl, '/') . '/collections';
        }
        if ($collectionsUrl !== null || isset($root['collections'])) {
            $log('STAC API ' . ($root['stac_version'] ?? '') . ': leggo le collezioni');
            $page = isset($root['collections']) ? $root : $this->getJson($collectionsUrl);
            $pageUrl = $collectionsUrl ?? $rootUrl;
            $seen = [];
            while (true) {
                foreach ($page['collections'] ?? [] as $c) {
                    if ($read++ >= $max) {
                        return;
                    }
                    yield $this->toRecord($c, self::link($c, 'self', $pageUrl) ?? $pageUrl);
                }
                $next = self::link($page, 'next', $pageUrl);
                if ($next === null || isset($seen[$next])) {
                    break;
                }
                $seen[$next] = true;
                $page = $this->getJson($next);
                $pageUrl = $next;
            }
            return;
        }

        // Catalogo statico: visita dei link "child"
        $log('Catalogo STAC statico: seguo i link ai sotto-cataloghi');
        $queue = [];
        foreach (self::links($root, 'child', $rootUrl) as $u) {
            $queue[] = [$u, 1];
        }
        $visited = [$rootUrl => true];
        $complete = true;
        $requests = 1;
        while ($queue && $read < $max && $requests < $maxRequests) {
            [$u, $depth] = array_shift($queue);
            if (isset($visited[$u])) {
                continue;
            }
            $visited[$u] = true;
            $requests++;
            try {
                $node = $this->getJson($u);
            } catch (\RuntimeException $e) {
                $log("Ignorato $u: " . $e->getMessage());
                $complete = false;
                continue;
            }
            if (($node['type'] ?? '') === 'Collection') {
                $read++;
                yield $this->toRecord($node, $u);
            }
            if ($depth < $maxDepth) {
                foreach (self::links($node, 'child', $u) as $child) {
                    $queue[] = [$child, $depth + 1];
                }
            }
        }
        if ($requests >= $maxRequests) {
            $log("Raggiunto il limite di $maxRequests richieste (parametro max_requests)");
        }
        return $complete && !$queue;
    }

    public function toRecord(array $c, string $url): Record
    {
        $id = (string) ($c['id'] ?? $url);
        $r = new Record($id, trim((string) ($c['title'] ?? '')) ?: $id);
        $r->externalId = $id;
        $r->resourceType = 'series';   // una collezione STAC e' un insieme di item omogenei
        $r->abstract = $c['description'] ?? null;
        $r->keywords = array_values(array_filter((array) ($c['keywords'] ?? []), 'is_string'));
        $r->metadataStandard = 'STAC ' . ($c['stac_version'] ?? '');
        $r->metadataUrl = $url;
        $r->rawMetadata = json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $lic = array_filter([$c['license'] ?? null, self::link($c, 'license', $url)]);
        $r->licenseText = $lic ? implode(' | ', $lic) : null;

        foreach ($c['providers'] ?? [] as $prov) {
            if (!empty($prov['name'])) {
                $role = 'other';
                foreach ((array) ($prov['roles'] ?? []) as $pr) {
                    if (isset(self::ROLES[$pr])) {
                        $role = self::ROLES[$pr];
                        break;
                    }
                }
                $r->addParty($prov['name'], $role, null, $prov['url'] ?? null);
            }
        }

        $bbox = $c['extent']['spatial']['bbox'][0] ?? null;
        if (is_array($bbox)) {
            $r->bbox = Geo::bboxOfGeoJson(['bbox' => $bbox]);
        }
        $interval = $c['extent']['temporal']['interval'][0] ?? null;
        if (is_array($interval)) {
            $r->dateCreated = Xml::date($interval[0] ?? null);
        }
        $r->dateRevised = Xml::date($c['updated'] ?? null);
        $epsg = $c['summaries']['proj:epsg'] ?? $c['summaries']['proj:code'] ?? [];
        $r->crs = Xml::epsg(array_map('strval', array_filter((array) $epsg, 'is_scalar')));

        $r->addEndpoint(new Endpoint($url, 'stac', null, 'STAC', $c['stac_version'] ?? null, null, 'Collezione STAC'));
        if ($items = self::link($c, 'items', $url)) {
            $r->addEndpoint(new Endpoint($items, 'stac', null, 'STAC items', $c['stac_version'] ?? null, null, 'Item della collezione'));
        }
        foreach ($c['assets'] ?? [] as $key => $a) {
            if (empty($a['href'])) {
                continue;
            }
            $href = self::resolve($url, $a['href']);
            $ep = $this->classifier->classify($href, $a['type'] ?? null, $a['title'] ?? (string) $key);
            if ($ep !== null) {
                if ($ep->type === 'webpage') {
                    $ep->type = 'download';
                }
                // il media type (es. COG) e' piu' preciso dell'estensione del file
                $ep->format = EndpointClassifier::formatCode($a['type'] ?? null) ?? $ep->format;
                $r->addEndpoint($ep);
            }
        }
        return $r;
    }

    private function getJson(string $url): array
    {
        $res = $this->http->get($url, ['Accept: application/json, application/geo+json']);
        if (!$res->ok()) {
            throw new \RuntimeException("$url: " . $res->problem());
        }
        $j = json_decode($res->body, true);
        if (!is_array($j)) {
            throw new \RuntimeException("$url: risposta non JSON");
        }
        return $j;
    }

    private static function link(array $doc, string $rel, string $base): ?string
    {
        return self::links($doc, $rel, $base)[0] ?? null;
    }

    private static function links(array $doc, string $rel, string $base): array
    {
        $out = [];
        foreach ($doc['links'] ?? [] as $l) {
            if (($l['rel'] ?? '') === $rel && !empty($l['href']) && strtoupper($l['method'] ?? 'GET') === 'GET') {
                $out[] = self::resolve($base, $l['href']);
            }
        }
        return $out;
    }

    private static function resolve(string $base, string $href): string
    {
        return WebPageHarvester::resolve($base, $href) ?? $href;
    }
}
