<?php
declare(strict_types=1);
// Verifiche dello strumento SPARQL che non richiedono database ne' rete:  php tests/sparql-test.php
// (la prova completa usa il finto endpoint: python tests/sparql-mock.py 8098, vedi tests/README o README.md)

define('GWC_SKIP_CONFIG', true);
require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Sparql\QueryBuilder as QB;
use GeoWebCrawler\Sparql\SparqlService as S;

$fail = 0;
function check(string $what, mixed $got, mixed $expected): void
{
    global $fail;
    if ($got === $expected) {
        echo "ok   $what\n";
    } else {
        $fail++;
        echo "NO   $what\n     atteso: " . var_export($expected, true) . "\n     trovato: " . var_export($got, true) . "\n";
    }
}

check('parole chiave', QB::keywords('Indirizzi, "numeri civici" a roma'), ['indirizzi', 'numeri civici', 'roma']);
check('nome locale', QB::localName('https://w3id.org/italia/onto/CLV/fullAddress'), 'fullAddress');
check('forma breve', QB::short('http://dati.beniculturali.it/cis/hasSite'), 'cis:hasSite');
check('letterale', QB::literal('a "b" \\c'), '"a \\"b\\" \\\\c"');

$v = (new QB(true))->searchText(['numeri', 'via']);
check('Virtuoso usa bif:contains', str_contains($v, "?t bif:contains \"'numer*' AND 'via'\""), true);
check('bif:contains nello stesso gruppo del triple pattern', (bool) preg_match('/GRAPH \?g \{\s*\?s \?tp \?t \.\s*\?t bif:contains/', $v), true);
check('radice italiana', [QB::stem('musei'), QB::stem('indirizzi'), QB::stem('via'), QB::stem('numeri civici')], ['muse', 'indirizz', 'via', 'numeri civici']);
$s = (new QB(false))->searchText(['museo']);
check('SPARQL standard usa CONTAINS', str_contains($s, 'CONTAINS(LCASE(STR(?t)), "muse")'), true);
check('prefissi solo quelli usati', str_contains($s, 'PREFIX rdfs:') && !str_contains($s, 'PREFIX arco:'), true);
check('filtro geo', str_contains((new QB())->searchClasses(['address'], true), 'FILTER EXISTS { ?s ?gp ?gx'), true);

$p = (new QB())->page('http://g', 'http://c', [['name' => 'x', 'path' => ['http://p1', 'http://p2']]], 100, 'http://s/9');
check('pagina dopo l\'ultimo oggetto', str_contains($p, 'FILTER (STR(?s) > "http://s/9")') && str_contains($p, 'LIMIT 100'), true);
check('catena di proprieta\'', str_contains($p, 'OPTIONAL { ?s <http://p1> ?c0_0 . ?c0_0 <http://p2> ?c0 }'), true);

try {
    QB::iri('http://x> } DROP');
    check('IRI non valido rifiutato', false, true);
} catch (InvalidArgumentException) {
    check('IRI non valido rifiutato', true, true);
}

check('geo: latitudine', S::geoKind('http://www.w3.org/2003/01/geo/wgs84_pos#lat'), 'coordinate');
check('geo: wkt', S::geoKind('http://www.opengis.net/ont/geosparql#asWKT'), 'wkt');
check('geo: indirizzo', S::geoKind('https://w3id.org/italia/onto/CLV/fullAddress'), 'indirizzo');
check('geo: nessuno', S::geoKind('http://www.w3.org/2000/01/rdf-schema#label'), null);

$profile = [
    '' => [['p' => 'http://www.w3.org/2000/01/rdf-schema#label', 'name' => 'label', 'iri' => false, 'geo' => null],
           ['p' => 'http://x/hasSite', 'name' => 'hasSite', 'iri' => true, 'geo' => null]],
    'http://x/hasSite' => [['p' => 'http://www.w3.org/2003/01/geo/wgs84_pos#lat', 'name' => 'lat', 'iri' => false, 'geo' => 'coordinate']],
];
check('colonne proposte', array_column(S::defaultColumns($profile), 'name'), ['label', 'hasSite_lat']);
check('nomi unici', array_column(S::uniqueNames([['name' => 'a b', 'path' => []], ['name' => 'a_b', 'path' => []]]), 'name'), ['a_b', 'a_b_2']);

echo $fail ? "\n$fail verifiche fallite\n" : "\nTutte le verifiche superate\n";
exit($fail ? 1 : 0);
