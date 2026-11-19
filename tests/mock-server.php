<?php
// Server di prova che simula un catalogo CSW e alcune pagine web, usando i file in tests/fixtures.
// Avvio:  php -S 127.0.0.1:8765 tests/mock-server.php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fx = __DIR__ . '/fixtures';

$pages = ['/page.html' => 'page.html', '/altri-dati.html' => 'page2.html'];
if (isset($pages[$path])) {
    header('Content-Type: text/html; charset=utf-8');
    readfile("$fx/{$pages[$path]}");
    return true;
}

if ($path === '/csw' || $path === '/csw-dconly') {
    // parametri dalla richiesta POST (XML) o GET (KVP)
    $body = file_get_contents('php://input');
    $q = array_change_key_case($_GET, CASE_LOWER);
    if ($body !== '') {
        preg_match('/outputSchema="([^"]+)"/', $body, $m1);
        preg_match('/startPosition="(\d+)"/', $body, $m2);
        preg_match('/maxRecords="(\d+)"/', $body, $m3);
        $q = ['outputschema' => $m1[1] ?? '', 'startposition' => $m2[1] ?? 1, 'maxrecords' => $m3[1] ?? 10];
    }
    $iso = str_contains($q['outputschema'] ?? '', 'isotc211');
    header('Content-Type: application/xml');
    if ($iso && $path === '/csw-dconly') {
        echo '<ows:ExceptionReport xmlns:ows="http://www.opengis.net/ows" version="1.0.0"><ows:Exception exceptionCode="InvalidParameterValue" locator="outputSchema"><ows:ExceptionText>Schema non supportato</ows:ExceptionText></ows:Exception></ows:ExceptionReport>';
        return true;
    }
    $doc = new DOMDocument();
    $doc->load($fx . ($iso ? '/iso_records.xml' : '/dc_records.xml'));
    $all = [];
    foreach ($doc->documentElement->childNodes as $n) {
        if ($n instanceof DOMElement) {
            $all[] = $doc->saveXML($n);
        }
    }
    $start = max(1, (int) ($q['startposition'] ?? 1));
    $max = max(1, (int) ($q['maxrecords'] ?? 10));
    $slice = array_slice($all, $start - 1, $max);
    $next = $start + count($slice);
    if ($next > count($all)) {
        $next = 0;
    }
    printf('<csw:GetRecordsResponse xmlns:csw="http://www.opengis.net/cat/csw/2.0.2" xmlns:gmd="http://www.isotc211.org/2005/gmd" xmlns:gco="http://www.isotc211.org/2005/gco" xmlns:gmx="http://www.isotc211.org/2005/gmx" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dct="http://purl.org/dc/terms/" xmlns:ows="http://www.opengis.net/ows" version="2.0.2">'
        . '<csw:SearchStatus timestamp="2026-01-01T00:00:00"/>'
        . '<csw:SearchResults numberOfRecordsMatched="%d" numberOfRecordsReturned="%d" nextRecord="%d" elementSet="full">%s</csw:SearchResults></csw:GetRecordsResponse>',
        count($all), count($slice), $next, implode('', $slice));
    return true;
}

http_response_code(404);
echo 'not found';
return true;
