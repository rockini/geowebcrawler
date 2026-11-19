<?php
// Verifica rapida dei parser sui file di esempio, senza database ne' rete.
// Uso:  php tests/parsers-test.php
declare(strict_types=1);

define('GWC_SKIP_CONFIG', true);
require __DIR__ . '/../src/bootstrap.php';

use GeoWebCrawler\Harvest\{CkanHarvester, DublinCoreParser, EndpointClassifier, Geo, Iso19139Parser, OgcCapabilitiesHarvester, StacHarvester, WebPageHarvester, Xml};
use GeoWebCrawler\Harvest\Rdf\{DcatMapper, RdfXmlParser};

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


// GetCapabilities OGC
$ogc = new OgcCapabilitiesHarvester();
$wms = iterator_to_array($ogc->parse(Xml::load(file_get_contents(__DIR__ . '/fixtures/wms130.xml')), 'WMS', 'https://x/wms?SERVICE=WMS&REQUEST=GetCapabilities'), false);
check('WMS 1.3: servizio + 3 layer con nome', count($wms), 4);
check('WMS 1.3: titolo servizio', $wms[0]->title, 'Geoportale Regionale - Servizio WMS');
check('WMS 1.3: layer annidato', $wms[2]->endpoints[0]->layerName, 'idro:fiumi');
check('WMS 1.3: CRS ereditati', $wms[2]->crs, [4326, 6707, 3003]);
check('WMS 1.3: bbox ereditato dal padre', $wms[3]->bbox, [9.5, 42.2, 12.5, 44.5]);
check('WMS 1.3: MetadataURL', $wms[1]->metadataUrl, 'https://geo.regione.example/csw?request=GetRecordById&id=orto2022');
check('WMS 1.3: "Fees none" escluso dalla licenza', $wms[0]->licenseText, 'CC BY 4.0');
$wfs = iterator_to_array($ogc->parse(Xml::load(file_get_contents(__DIR__ . '/fixtures/wfs200.xml')), 'WFS', 'https://x/wfs'), false);
check('WFS 2.0: feature type', [$wfs[1]->title, $wfs[1]->bbox, $wfs[1]->crs], ['Fiumi', [10.0, 43.0, 11.0, 44.0], [6707, 4326]]);
check('WFS 2.0: ente dal ServiceProvider', $wfs[1]->parties[0]['name'], 'Regione Esempio');
$wms111 = iterator_to_array($ogc->parse(Xml::load(file_get_contents(__DIR__ . '/fixtures/wms111.xml')), 'WMS', 'https://x/wms'), false);
check('WMS 1.1.1: LatLonBoundingBox', $wms111[1]->bbox, [11.1, 43.7, 11.4, 43.9]);
check('URL base senza parametri OGC', OgcCapabilitiesHarvester::baseUrl('https://x/wms?map=ctr&SERVICE=WMS&request=GetCapabilities'), 'https://x/wms?map=ctr');

// CKAN
$ck = json_decode(file_get_contents(__DIR__ . '/fixtures/ckan_search.json'), true)['result']['results'];
$c0 = (new CkanHarvester())->toRecord($ck[0], 'https://portale.example');
check('CKAN: link classificati', array_map(fn($e) => $e->type . ':' . $e->format, $c0->endpoints), ['download:zip', 'wfs:', 'download:geojson']);
check('CKAN: bbox da extra spatial', $c0->bbox, [11.1, 43.7, 11.4, 43.9]);
check('CKAN: titolare DCAT-AP_IT', $c0->parties[1], ['name' => 'Comune di Esempio', 'role' => 'owner', 'email' => null, 'url' => null]);
check('CKAN: URL API', CkanHarvester::apiUrl('https://www.dati.gov.it/opendata/'), ['https://www.dati.gov.it/opendata/api/3/action/package_search', 'https://www.dati.gov.it/opendata']);

// DCAT-AP / GeoDCAT-AP
$g = (new RdfXmlParser())->parse(Xml::load(file_get_contents(__DIR__ . '/fixtures/dcat_page1.rdf'))->documentElement);
$dc1 = iterator_to_array((new DcatMapper())->records($g), false);
check('DCAT: dataset letti', count($dc1), 2);
check('DCAT: titolo in italiano', $dc1[0]->title, 'Uso del suolo 2020');
check('DCAT: bbox da WKT', $dc1[0]->bbox, [9.5, 42.2, 12.5, 44.5]);
check('DCAT: bbox da GeoJSON (parseType Resource)', $dc1[1]->bbox, [10.0, 43.0, 11.0, 44.0]);
check('DCAT: EPSG da conformsTo', $dc1[0]->crs, [6707]);
check('DCAT: formato da URI EU', $dc1[0]->endpoints[0]->format, 'shp');
check('DCAT: accessURL uguale al downloadURL non ripetuto', count($dc1[0]->endpoints), 2);
check('DCAT: email del contatto', $dc1[0]->parties[1]['email'], 'sit@regione.example');
check('DCAT: editore da riferimento rdf:resource', $dc1[1]->parties[0]['name'], 'Regione Esempio');
check('DCAT: pagina successiva Hydra', DcatMapper::nextPage($g), 'http://127.0.0.1:8765/catalog.rdf?page=2');
$g2 = (new RdfXmlParser())->parse(Xml::load(file_get_contents(__DIR__ . '/fixtures/dcat_page2.rdf'))->documentElement);
$dc2 = iterator_to_array((new DcatMapper())->records($g2), false);
check('DCAT: accessService non ripetuto come risorsa', count($dc2), 1);
check('DCAT: serie e servizio WCS', [$dc2[0]->resourceType, $dc2[0]->endpoints[0]->type], ['series', 'wcs']);

// STAC
$col = json_decode(file_get_contents(__DIR__ . '/fixtures/stac_collections2.json'), true)['collections'][0];
$st = (new StacHarvester())->toRecord($col, 'https://stac.example/collections/dem-italia');
check('STAC: asset COG', [$st->endpoints[1]->type, $st->endpoints[1]->format], ['download', 'cog']);
check('STAC: bbox ed estensione temporale', [$st->bbox, $st->dateCreated], [[6.6, 35.5, 18.5, 47.1], '2020-01-01']);
check('Geo: bbox STAC 3D', Geo::bboxOfGeoJson(['bbox' => [10, 40, 0, 12, 42, 100]]), [10.0, 40.0, 12.0, 42.0]);
check('Geo: GML con EPSG:4326 lat/lon', Geo::bboxOfGml('<gml:Envelope srsName="urn:ogc:def:crs:EPSG::4326"><gml:lowerCorner>42 10</gml:lowerCorner><gml:upperCorner>44 12</gml:upperCorner></gml:Envelope>'), [10.0, 42.0, 12.0, 44.0]);
$nd = Xml::load('<a><c>3003</c><c>2020</c></a>');
check('Xml::texts restituisce stringhe anche per valori numerici', Xml::texts(Xml::xpath($nd), '//c'), ['3003', '2020']);
check('EPSG da codice solo numerico', Xml::epsg(Xml::texts(Xml::xpath($nd), '//c')), [3003, 2020]);
check('CSW: URL senza parametri OGC', \GeoWebCrawler\Harvest\CswHarvester::endpointUrl('https://geo.example/geonetwork/srv/eng/csw?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetCapabilities'), 'https://geo.example/geonetwork/srv/eng/csw');
check('CSW: parametri del server conservati', \GeoWebCrawler\Harvest\CswHarvester::endpointUrl('https://x/cgi?map=cat.map&request=GetCapabilities'), 'https://x/cgi?map=cat.map');

echo $fail ? "\n$fail verifiche fallite\n" : "\nTutte le verifiche superate\n";
exit($fail ? 1 : 0);
