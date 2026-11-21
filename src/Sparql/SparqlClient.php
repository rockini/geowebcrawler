<?php
declare(strict_types=1);

namespace GeoWebCrawler\Sparql;

use GeoWebCrawler\Http\HttpClient;

/**
 * Invio di query a un endpoint SPARQL 1.1 (protocollo HTTP standard).
 * Con l'opzione "virtuoso" si aggiungono i parametri di OpenLink Virtuoso (formato e tempo massimo).
 */
final class SparqlClient
{
    private HttpClient $http;
    public int $lastMs = 0;

    /** @param array $endpoint riga di gwc.sparql_endpoints (options gia' decodificate o in JSON) */
    public function __construct(private array $endpoint, ?HttpClient $http = null)
    {
        if (is_string($this->endpoint['options'] ?? null)) {
            $this->endpoint['options'] = json_decode($this->endpoint['options'], true) ?: [];
        }
        $this->http = $http ?? new HttpClient((int) ($this->option('timeout', 120)));
    }

    public function option(string $k, mixed $default = null): mixed
    {
        return $this->endpoint['options'][$k] ?? $default;
    }

    public function isVirtuoso(): bool
    {
        return (bool) $this->option('virtuoso', false);
    }

    /**
     * Query SELECT: righe come array nome => valore (stringa), piu' "_types" con il tipo di ogni valore.
     * @return array{vars: string[], rows: array<int, array<string, string>>, types: array<int, array<string, string>>}
     */
    public function select(string $query): array
    {
        $r = $this->send($query, 'application/sparql-results+json');
        $json = json_decode($r->body, true);
        if (!is_array($json) || !isset($json['results']['bindings'])) {
            throw new SparqlException('Risposta non valida (attesi risultati JSON): ' . self::snippet($r->body));
        }
        $vars = $json['head']['vars'] ?? [];
        $rows = [];
        $types = [];
        foreach ($json['results']['bindings'] as $b) {
            $row = [];
            $t = [];
            foreach ($b as $k => $v) {
                $row[$k] = (string) ($v['value'] ?? '');
                $t[$k] = (string) ($v['type'] ?? '') . (isset($v['datatype']) ? '^' . $v['datatype'] : '');
            }
            $rows[] = $row;
            $types[] = $t;
        }
        return ['vars' => $vars, 'rows' => $rows, 'types' => $types];
    }

    /** Query CONSTRUCT/DESCRIBE: testo RDF e tipo (N-Triples se il server lo sa fare). @return array{0:string,1:string} */
    public function graph(string $query): array
    {
        $r = $this->send($query, 'application/n-triples, text/plain;q=0.9, text/turtle;q=0.8, application/rdf+xml;q=0.5');
        return [$r->body, strtolower(trim(explode(';', (string) $r->contentType)[0]))];
    }

    private function send(string $query, string $accept): \GeoWebCrawler\Http\HttpResponse
    {
        $params = ['query' => $query];
        if ($this->isVirtuoso()) {
            // Virtuoso: formato esplicito e tempo massimo di esecuzione (millisecondi)
            if (str_contains($accept, 'sparql-results+json')) {
                $params['format'] = 'application/sparql-results+json';
            }
            $params['timeout'] = (string) (1000 * (int) $this->option('timeout', 120));
        }
        $url = (string) $this->endpoint['url'];
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $headers = ['Accept: ' . $accept];
        $start = microtime(true);
        // le query lunghe vanno in POST (gli URL hanno un limite di lunghezza)
        $r = strlen($body) < 1800
            ? $this->http->get($url . (str_contains($url, '?') ? '&' : '?') . $body, $headers)
            : $this->http->request('POST', $url, $body, array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']));
        $this->lastMs = (int) round((microtime(true) - $start) * 1000);
        if (!$r->ok()) {
            throw new SparqlException('Il server SPARQL ha risposto ' . $r->problem() . ': ' . self::snippet($r->body));
        }
        return $r;
    }

    private static function snippet(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?? '');
        return mb_substr($s, 0, 600) ?: '(risposta vuota)';
    }
}
