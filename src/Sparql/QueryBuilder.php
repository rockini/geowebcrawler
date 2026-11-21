<?php
declare(strict_types=1);

namespace GeoWebCrawler\Sparql;

/**
 * Costruisce le query SPARQL usate dallo strumento. Le query vengono mostrate all'utente, che puo'
 * modificarle prima di spedirle: per questo sono scritte in modo leggibile, con i prefissi usati.
 */
final class QueryBuilder
{
    /** Prefissi comuni nei dati pubblici italiani (MiC/ArCo/Cultural-ON, ontologie della PA) e standard. */
    public const PREFIXES = [
        'rdf'       => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs'      => 'http://www.w3.org/2000/01/rdf-schema#',
        'owl'       => 'http://www.w3.org/2002/07/owl#',
        'xsd'       => 'http://www.w3.org/2001/XMLSchema#',
        'skos'      => 'http://www.w3.org/2004/02/skos/core#',
        'dc'        => 'http://purl.org/dc/elements/1.1/',
        'dcterms'   => 'http://purl.org/dc/terms/',
        'dcat'      => 'http://www.w3.org/ns/dcat#',
        'void'      => 'http://rdfs.org/ns/void#',
        'foaf'      => 'http://xmlns.com/foaf/0.1/',
        'schema'    => 'http://schema.org/',
        'geo'       => 'http://www.w3.org/2003/01/geo/wgs84_pos#',
        'geosparql' => 'http://www.opengis.net/ont/geosparql#',
        'locn'      => 'http://www.w3.org/ns/locn#',
        'cis'       => 'http://dati.beniculturali.it/cis/',
        'arco'      => 'https://w3id.org/arco/ontology/arco/',
        'a-loc'     => 'https://w3id.org/arco/ontology/location/',
        'a-cd'      => 'https://w3id.org/arco/ontology/context-description/',
        'clvapit'   => 'https://w3id.org/italia/onto/CLV/',
        'l0'        => 'https://w3id.org/italia/onto/l0/',
        'smapit'    => 'https://w3id.org/italia/onto/SM/',
        'tiapit'    => 'https://w3id.org/italia/onto/TI/',
    ];

    /** Proprieta' che di solito contengono un nome o un titolo. */
    public const TEXT_PROPS = ['rdfs:label', 'dcterms:title', 'dc:title', 'skos:prefLabel', 'l0:name', 'schema:name',
                               'foaf:name', 'clvapit:fullAddress', 'dcterms:description', 'rdfs:comment'];

    /** Proprieta' che indicano dati georeferenziati o georeferenziabili (coordinate, geometrie, indirizzi). */
    public const GEO_PROPS = ['geo:lat', 'geo:long', 'geo:location', 'geosparql:hasGeometry', 'geosparql:asWKT', 'locn:geometry',
                              'locn:address', 'schema:geo', 'schema:address', 'clvapit:hasAddress', 'clvapit:hasGeometry',
                              'clvapit:fullAddress', 'clvapit:hasStreetToponym', 'clvapit:hasNumber', 'clvapit:hasCity',
                              'cis:hasSite', 'cis:siteAddress', 'a-loc:hasCulturalPropertyAddress', 'a-loc:hasGeometry',
                              'a-loc:hasLocation', 'clvapit:hasSpatialCoverage'];

    public function __construct(private bool $virtuoso = false) {}

    /** Blocco PREFIX per le sole abbreviazioni usate nel testo. */
    public static function prefixesFor(string $body): string
    {
        $out = '';
        foreach (self::PREFIXES as $p => $iri) {
            if (preg_match('/(?<![\w-])' . preg_quote($p, '/') . ':/', $body) && !preg_match('/PREFIX\s+' . preg_quote($p, '/') . ':/i', $body)) {
                $out .= "PREFIX $p: <$iri>\n";
            }
        }
        return $out;
    }

    public static function withPrefixes(string $body): string
    {
        return self::prefixesFor($body) . ($body !== '' && self::prefixesFor($body) !== '' ? "\n" : '') . $body;
    }

    /** Parole chiave: separate da spazi o virgole; "tra virgolette" resta un'unica frase. @return string[] */
    public static function keywords(string $s): array
    {
        preg_match_all('/"([^"]+)"|([^\s,;]+)/u', $s, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $w = trim($x[1] !== '' ? $x[1] : ($x[2] ?? ''));
            if (mb_strlen($w) >= 2) {
                $out[] = mb_strtolower($w);
            }
        }
        return array_values(array_unique($out));
    }

    public static function literal(string $s): string
    {
        return '"' . strtr($s, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r']) . '"';
    }

    public static function iri(string $iri): string
    {
        if (preg_match('/[\s<>"{}|^`\\\\]/', $iri)) {
            throw new \InvalidArgumentException("IRI non valido: $iri");
        }
        return "<$iri>";
    }

    /** Forma breve di un IRI con i prefissi noti (per mostrarlo). */
    public static function short(string $iri): string
    {
        foreach (self::PREFIXES as $p => $ns) {
            if (str_starts_with($iri, $ns) && preg_match('/^[\w.-]*$/u', substr($iri, strlen($ns)))) {
                return $p . ':' . substr($iri, strlen($ns));
            }
        }
        return $iri;
    }

    /** Nome locale di un IRI (dopo l'ultimo # o /). */
    public static function localName(string $iri): string
    {
        $n = preg_replace('~^.*[#/]~', '', rtrim($iri, '/#'));
        return $n !== '' && $n !== null ? $n : $iri;
    }

    /** Filtro: il testo di ?var contiene tutte le parole (senza distinguere maiuscole). */
    private function containsAll(string $var, array $words): string
    {
        return implode(' && ', array_map(fn($w) => "CONTAINS(LCASE(STR($var)), " . self::literal($w) . ')', $words));
    }

    /** Espressione per bif:contains di Virtuoso (indice testuale): 'parola*' AND 'altra*'. */
    private function bifExpression(array $words): string
    {
        $parts = [];
        foreach ($words as $w) {
            $w = preg_replace("/['\"*]/u", ' ', $w);
            $w = trim(preg_replace('/\s+/u', ' ', (string) $w));
            // il carattere jolly richiede almeno 4 lettere prima dell'asterisco
            $parts[] = "'" . $w . (mb_strlen($w) >= 4 && !str_contains($w, ' ') ? '*' : '') . "'";
        }
        return self::literal(implode(' AND ', $parts));
    }

    private function graphClause(string $graph, string $pattern): string
    {
        return $graph === '' ? $pattern : 'GRAPH ' . self::iri($graph) . " { $pattern }";
    }

    private function geoFilter(string $s): string
    {
        return "  FILTER EXISTS { $s ?gp ?gx . VALUES ?gp { " . implode(' ', self::GEO_PROPS) . " } }\n";
    }

    /**
     * Gruppi = classi il cui nome (IRI o etichetta) contiene le parole chiave, con il numero di istanze per grafo.
     */
    public function searchClasses(array $words, bool $geoOnly = false, int $limit = 200): string
    {
        $filter = '(' . $this->containsAll('?class', $words) . ') || (BOUND(?cl) && ' . $this->containsAll('?cl', $words) . ')';
        $q = "# Classi (tipi di oggetti) il cui nome contiene: " . implode(', ', $words) . "\n"
           . "SELECT ?g ?class (SAMPLE(?cl) AS ?label) (COUNT(DISTINCT ?s) AS ?n) (SAMPLE(?s) AS ?esempio)\n"
           . "WHERE {\n"
           . "  { SELECT DISTINCT ?class WHERE { [] a ?class } }\n"
           . "  OPTIONAL { ?class rdfs:label ?cl }\n"
           . "  FILTER ($filter)\n"
           . "  GRAPH ?g { ?s a ?class }\n"
           . ($geoOnly ? $this->geoFilter('?s') : '')
           . "}\nGROUP BY ?g ?class\nORDER BY DESC(?n)\nLIMIT $limit";
        return self::withPrefixes($q);
    }

    /**
     * Gruppi = classi degli oggetti che hanno nome, titolo o descrizione con le parole chiave.
     */
    public function searchText(array $words, bool $geoOnly = false, int $limit = 200): string
    {
        $match = $this->virtuoso
            ? "  ?t bif:contains " . $this->bifExpression($words) . " .\n"
            : "  FILTER (" . $this->containsAll('?t', $words) . ")\n";
        $q = "# Oggetti con nome, titolo o descrizione che contiene: " . implode(', ', $words) . "\n"
           . "# raggruppati per classe e grafo\n"
           . "SELECT ?g ?class (COUNT(DISTINCT ?s) AS ?n) (SAMPLE(?s) AS ?esempio) (SAMPLE(?t) AS ?testo)\n"
           . "WHERE {\n"
           . "  VALUES ?tp { " . implode(' ', self::TEXT_PROPS) . " }\n"
           . "  GRAPH ?g { ?s ?tp ?t . ?s a ?class }\n"
           . $match
           . ($geoOnly ? $this->geoFilter('?s') : '')
           . "}\nGROUP BY ?g ?class\nORDER BY DESC(?n)\nLIMIT $limit";
        return self::withPrefixes($q);
    }

    /** Gruppi = dataset descritti con DCAT o VoID che hanno titolo o descrizione con le parole chiave. */
    public function searchDatasets(array $words, int $limit = 200): string
    {
        $q = "# Dataset (DCAT o VoID) con titolo o descrizione che contiene: " . implode(', ', $words) . "\n"
           . "SELECT ?ds (SAMPLE(?g0) AS ?g) (SAMPLE(?t) AS ?label) (SAMPLE(?d) AS ?descrizione)\n"
           . "       (GROUP_CONCAT(DISTINCT STR(?url); separator=\" \") AS ?urls)\n"
           . "WHERE {\n"
           . "  VALUES ?dt { dcat:Dataset void:Dataset }\n"
           . "  GRAPH ?g0 { ?ds a ?dt ; dcterms:title ?t }\n"
           . "  OPTIONAL { ?ds dcterms:description ?d }\n"
           . "  OPTIONAL { ?ds dcat:distribution ?dist . ?dist dcat:downloadURL|dcat:accessURL ?url }\n"
           . "  OPTIONAL { ?ds void:dataDump|void:sparqlEndpoint ?url }\n"
           . "  FILTER ((" . $this->containsAll('?t', $words) . ") || (BOUND(?d) && " . $this->containsAll('?d', $words) . "))\n"
           . "}\nGROUP BY ?ds\nLIMIT $limit";
        return self::withPrefixes($q);
    }

    /** Numero di istanze di una classe. */
    public function count(string $graph, string $class): string
    {
        return "SELECT (COUNT(DISTINCT ?s) AS ?n) WHERE { " . $this->graphClause($graph, '?s a ' . self::iri($class)) . " }";
    }

    /** Catena di proprieta': ?s <p1> ?v1 . ?v1 <p2> ?v2 ... restituisce [pattern, variabile finale]. */
    private function chain(string $from, array $path, string $prefix): array
    {
        $pat = [];
        $cur = $from;
        foreach (array_values($path) as $i => $p) {
            $next = $i === count($path) - 1 ? $prefix : "{$prefix}_$i";
            $pat[] = "$cur " . self::iri($p) . " $next";
            $cur = $next;
        }
        return [implode(' . ', $pat), $cur];
    }

    /**
     * Proprieta' usate da un campione di istanze della classe (o dai valori raggiunti seguendo $path).
     * Colonne: ?p, ?n (quante volte), ?esempio, ?iri (1 se i valori sono oggetti da esplorare).
     */
    public function profile(string $graph, string $class, array $path = [], int $sample = 300): string
    {
        [$pat, $end] = $path ? $this->chain('?s', $path, '?x') : ['', '?s'];
        $q = "# Proprieta' usate da $sample oggetti di esempio" . ($path ? ' (seguendo ' . implode(' / ', array_map([self::class, 'short'], $path)) . ')' : '') . "\n"
           . "SELECT ?p (COUNT(?o) AS ?n) (COUNT(DISTINCT $end) AS ?soggetti) (SAMPLE(?o) AS ?esempio)\n"
           . "       (MAX(IF(isIRI(?o) || isBlank(?o), 1, 0)) AS ?iri)\n"
           . "WHERE {\n"
           . "  { SELECT ?s WHERE { " . $this->graphClause($graph, '?s a ' . self::iri($class)) . " } LIMIT $sample }\n"
           . ($pat !== '' ? "  $pat .\n" : '')
           . "  $end ?p ?o .\n"
           . "}\nGROUP BY ?p\nORDER BY DESC(?soggetti)\nLIMIT 300";
        return $q;
    }

    /**
     * Una pagina di dati: $limit soggetti (ordinati per IRI, dopo $after) con le colonne richieste.
     * @param array<int, array{name:string, path:string[]}> $columns
     */
    public function page(string $graph, string $class, array $columns, int $limit, ?string $after = null, int $offset = 0): string
    {
        $inner = $this->graphClause($graph, '?s a ' . self::iri($class));
        $filter = $after !== null ? ' FILTER (STR(?s) > ' . self::literal($after) . ')' : '';
        $sub = $after !== null || $offset === 0
            ? "SELECT ?s WHERE { $inner$filter } ORDER BY STR(?s) LIMIT $limit"
            : "SELECT ?s WHERE { $inner } ORDER BY STR(?s) LIMIT $limit OFFSET $offset";
        $vars = ['?s'];
        $opts = '';
        foreach (array_values($columns) as $i => $c) {
            [$pat, $end] = $this->chain('?s', $c['path'], "?c$i");
            $vars[] = $end;
            $opts .= "  OPTIONAL { $pat }\n";
        }
        return "SELECT " . implode(' ', $vars) . "\nWHERE {\n  { $sub }\n$opts}";
    }

    /** Una pagina di dati RDF completi (tutte le triple dei soggetti). */
    public function pageRdf(string $graph, string $class, int $limit, ?string $after = null): string
    {
        $inner = $this->graphClause($graph, '?s a ' . self::iri($class));
        $filter = $after !== null ? ' FILTER (STR(?s) > ' . self::literal($after) . ')' : '';
        return "CONSTRUCT { ?s ?p ?o }\nWHERE {\n  { SELECT ?s WHERE { $inner$filter } ORDER BY STR(?s) LIMIT $limit }\n  ?s ?p ?o .\n}";
    }

    /** Ultimo soggetto di una pagina (serve per chiedere la pagina successiva). */
    public function subjectsQuery(string $graph, string $class, int $limit, ?string $after = null): string
    {
        $inner = $this->graphClause($graph, '?s a ' . self::iri($class));
        $filter = $after !== null ? ' FILTER (STR(?s) > ' . self::literal($after) . ')' : '';
        return "SELECT ?s WHERE { $inner$filter } ORDER BY STR(?s) LIMIT $limit";
    }

    /** Modelli per l'editor di query. @return array<string, string> titolo => query */
    public static function templates(): array
    {
        return [
            'Classi piu\' usate' => "SELECT ?class (COUNT(?s) AS ?n) WHERE { ?s a ?class } GROUP BY ?class ORDER BY DESC(?n) LIMIT 100",
            'Grafi presenti' => "SELECT ?g (COUNT(*) AS ?triple) WHERE { GRAPH ?g { ?s ?p ?o } } GROUP BY ?g ORDER BY DESC(?triple) LIMIT 100",
            'Oggetti con coordinate' => self::withPrefixes("SELECT ?s ?nome ?lat ?long WHERE {\n  ?s geo:lat ?lat ; geo:long ?long .\n  OPTIONAL { ?s rdfs:label ?nome }\n} LIMIT 100"),
            'Indirizzi (CLV)' => self::withPrefixes("SELECT ?indirizzo ?testo ?civico WHERE {\n  ?indirizzo a clvapit:Address .\n  OPTIONAL { ?indirizzo clvapit:fullAddress ?testo }\n  OPTIONAL { ?indirizzo clvapit:hasNumber/clvapit:streetNumber ?civico }\n} LIMIT 100"),
            'Luoghi della cultura (Cultural-ON)' => self::withPrefixes("SELECT ?luogo ?nome ?indirizzo WHERE {\n  ?luogo a cis:CulturalInstituteOrSite ; rdfs:label ?nome .\n  OPTIONAL { ?luogo cis:hasSite/cis:siteAddress/clvapit:fullAddress ?indirizzo }\n} LIMIT 100"),
            'Proprieta\' di una classe' => self::withPrefixes("SELECT ?p (COUNT(*) AS ?n) WHERE {\n  { SELECT ?s WHERE { ?s a cis:CulturalInstituteOrSite } LIMIT 500 }\n  ?s ?p ?o .\n} GROUP BY ?p ORDER BY DESC(?n)"),
            'Dataset DCAT' => self::withPrefixes("SELECT ?ds ?titolo ?url WHERE {\n  ?ds a dcat:Dataset ; dcterms:title ?titolo .\n  OPTIONAL { ?ds dcat:distribution/dcat:downloadURL ?url }\n} LIMIT 100"),
        ];
    }
}
