<?php
// Verifica rapida dei parser sui file di esempio, senza database ne' rete.
// Uso:  php tests/parsers-test.php
declare(strict_types=1);

define('GWC_SKIP_CONFIG', true);
require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Harvest\{DublinCoreParser, EndpointClassifier, Iso19139Parser, WebPageHarvester, Xml};

$fail = 0;
function check(string $what, mixed $got, mixed $expected): void {
    global $fail;
    $ok = $got === $expected;
    if (!$ok) { $fail++; }
    printf("%s %s%s\n", $ok ? 'OK  ' : 'FAIL', $what, $ok ? '' : ' -> ottenuto ' . var_export($got, true) . ', atteso ' . var_export($expected, true));
}

// ISO 19139
$doc = Xml::load(file_get_contents(__DIR__ . '/fixtures/iso_records.xml'));
$xp = Xml::xpath($doc);
$recs = [];
foreach ($xp->query('/*/gmd:MD_Metadata') as $md) { $recs[] = (new Iso19139Parser())->parse($xp, $md); }
check('ISO: record letti', count($recs), 3);
check('ISO: titolo', $recs[0]->title, 'Carta Tecnica Regionale 1:10.000 - Toscana');
check('ISO: data pubblicazione', $recs[0]->datePublished, '2019-01-15');
check('ISO: data revisione', $recs[0]->dateRevised, '2024-11-30');
check('ISO: EPSG da gmx:Anchor', $recs[0]->crs, [6707]);
check('ISO: bbox', $recs[0]->bbox, [9.68, 42.23, 12.37, 44.47]);
check('ISO: parole chiave', $recs[0]->keywords, ['Elevazione', 'cartografia']);
check('ISO: ruolo ente', $recs[0]->parties[0]['role'], 'owner');
check('ISO: tipi di accesso', array_map(fn($e) => $e->type, $recs[0]->endpoints), ['wms', 'download']);
check('ISO: versione WMS', $recs[0]->endpoints[0]->serviceVersion, '1.3.0');
check('ISO: layer WMS', $recs[0]->endpoints[0]->layerName, 'rt_ctr.10k');
check('ISO: formato dichiarato', $recs[0]->endpoints[1]->format, 'shp');
check('ISO: layer WFS', $recs[1]->endpoints[0]->layerName, 'idro:reticolo');

// Dublin Core
$doc = Xml::load(file_get_contents(__DIR__ . '/fixtures/dc_records.xml'));
$xp = Xml::xpath($doc);
$dc = [];
foreach ($xp->query('/*/csw:Record') as $r) { $dc[] = (new DublinCoreParser())->parse($xp, $r); }
check('DC: record letti', count($dc), 2);
check('DC: bbox con ordine lat/lon', $dc[0]->bbox, [6.63, 35.49, 18.52, 47.09]);
check('DC: tipo servizio', $dc[1]->resourceType, 'service');
check('DC: autore', $dc[0]->parties[0]['name'], 'ISTAT');

// Pagine web
$w = new WebPageHarvester();
[$links] = $w->extractLinks(file_get_contents(__DIR__ . '/fixtures/page.html'), 'http://example.org/dati/index.html');
$c = new EndpointClassifier();
$geo = array_values(array_filter(array_map(fn($l) => $c->classify($l[0], null, $l[1], null, true), $links)));
check('HTML: link a dati riconosciuti (con doppioni)', count($geo), 5);
check('HTML: link relativo', $links[1][0], 'http://example.org/dati/files/edifici.gpkg');
check('HTML: URL nel testo', end($geo)->type, 'wms');
check('Risoluzione ../', WebPageHarvester::resolve('http://a.it/x/y/z.html', '../k/f.zip'), 'http://a.it/x/k/f.zip');

// Classificatore
check('Classifica ArcGIS REST', $c->classify('https://x.it/arcgis/rest/services/A/MapServer')?->type, 'arcgis_rest');
check('Classifica OGC API', $c->classify('https://x.it/ogc/collections/fiumi/items')?->type, 'ogcapi_features');
check('Ignora PDF in modalita\' rigorosa', $c->classify('https://x.it/a.pdf', null, null, null, true), null);

echo $fail ? "\n$fail verifiche fallite\n" : "\nTutte le verifiche superate\n";
exit($fail ? 1 : 0);
