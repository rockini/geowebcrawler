<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use GeoWebCrawler\Harvest\Rdf\DcatMapper;
use GeoWebCrawler\Harvest\Rdf\RdfXmlParser;
use GeoWebCrawler\Http\HttpClient;

/**
 * Legge un catalogo DCAT-AP / GeoDCAT-AP / DCAT-AP_IT pubblicato come file RDF/XML,
 * seguendo la paginazione Hydra (es. portali CKAN con ckanext-dcat: .../catalog.rdf?page=1).
 *
 * Parametri della sorgente (campo params):
 *   max_records  numero massimo di dataset (default: harvest.max_records in config.php, 25000)
 *   max_pages    numero massimo di pagine da seguire (default 1000)
 *   standard     nome del profilo da registrare nei metadati (default "DCAT-AP")
 */
final class DcatHarvester implements Harvester
{
    public function __construct(
        private HttpClient $http = new HttpClient(),
        private DcatMapper $mapper = new DcatMapper(),
    ) {}

    public function harvest(array $source, callable $log): \Generator
    {
        $p = $source['params'] ?? [];
        $max = max(1, (int) ($p['max_records'] ?? \GeoWebCrawler\Config::get('harvest.max_records', 25000)));
        $maxPages = max(1, (int) ($p['max_pages'] ?? 1000));
        $standard = (string) ($p['standard'] ?? 'DCAT-AP');

        $url = $source['url'];
        $seen = [];
        $read = 0;
        for ($page = 1; $url !== null && $page <= $maxPages && $read < $max; $page++) {
            if (isset($seen[$url])) {
                break;
            }
            $seen[$url] = true;
            $res = $this->http->get($url, ['Accept: application/rdf+xml, application/xml;q=0.9']);
            if (!$res->ok()) {
                throw new \RuntimeException("Pagina $page non raggiungibile ($url): " . $res->problem());
            }
            $body = ltrim($res->body);
            if (!str_starts_with($body, '<')) {
                throw new \RuntimeException('Il catalogo non e\' in formato RDF/XML (' . ($res->contentType ?? 'tipo sconosciuto')
                    . '). Se il portale lo permette, usa l\'indirizzo della versione .rdf o aggiungi ?format=rdf');
            }
            $doc = Xml::load($body);
            if ($doc === null) {
                throw new \RuntimeException("Pagina $page: XML non valido");
            }
            $graph = (new RdfXmlParser())->parse($doc->documentElement);
            $n = 0;
            foreach ($this->mapper->records($graph, $standard) as $r) {
                if ($read >= $max) {
                    break;
                }
                $read++;
                $n++;
                yield $r;
            }
            $log("Pagina $page: $n dataset");
            $next = DcatMapper::nextPage($graph);
            $url = $next !== null && $next !== $url ? $next : null;
        }
    }
}
