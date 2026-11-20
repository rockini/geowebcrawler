<?php
declare(strict_types=1);
// Diagnostica di un catalogo CSW che risponde con errori (es. NullPointerException di GeoNetwork).
// Prova la stessa richiesta GetRecords in modi diversi e dice quali funzionano.
// Uso:
//   php bin/csw-test.php http://webgis2.regione.sardegna.it/geonetwork/srv/ita/csw
// Non usa il database ne' config.php (salvo http.ca_bundle, se config.php esiste).

$url = $argv[1] ?? '';
if (!preg_match('~^https?://~i', $url)) {
    fwrite(STDERR, "Uso: php bin/csw-test.php <indirizzo del CSW>\n");
    exit(1);
}
$url = preg_replace('~\?.*$~', '', $url);
$ca = null;
if (is_file(__DIR__ . '/../config/config.php')) {
    $cfg = require __DIR__ . '/../config/config.php';
    $ca = $cfg['http']['ca_bundle'] ?? null;
}

$query = 'service=CSW&version=2.0.2&request=GetRecords&typeNames=csw:Record&resultType=results'
       . '&elementSetName=full&outputSchema=http%3A%2F%2Fwww.isotc211.org%2F2005%2Fgmd&startPosition=1&maxRecords=3';
$filter = '&constraintLanguage=CQL_TEXT&constraint_language_version=1.1.0&constraint=AnyText%20like%20%27%25%27';
$caps = 'service=CSW&request=GetCapabilities';

function call(string $url, array $o, ?string $ca, ?string $jar, ?string $body = null): array
{
    $ch = curl_init($url);
    $h = [];
    if ($o['accept'] !== null) {
        $h[] = 'Accept: ' . $o['accept'];
    }
    if ($o['lang'] !== null) {
        $h[] = 'Accept-Language: ' . $o['lang'];
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/xml';
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => $o['ua'], CURLOPT_HTTPHEADER => $h, CURLOPT_ENCODING => '',
        CURLOPT_HEADER => true,
    ];
    if ($ca) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    if ($jar !== null) {
        $opts[CURLOPT_COOKIEFILE] = $jar;
        $opts[CURLOPT_COOKIEJAR] = $jar;
    }
    if ($body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $res = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'final'  => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'head'   => $raw === false ? '' : substr($raw, 0, $hs),
        'body'   => $raw === false ? '' : substr($raw, $hs),
        'error'  => $raw === false ? curl_error($ch) : null,
    ];
    unset($ch);   // chiude la connessione e scrive i cookie nel file
    return $res;
}

function verdict(array $r): string
{
    if ($r['error']) {
        return 'ERRORE rete: ' . $r['error'];
    }
    if (preg_match('/numberOfRecordsMatched="(\d+)"/', $r['body'], $m)) {
        return "OK ({$m[1]} record nel catalogo)";
    }
    if (preg_match('~<(?:\w+:)?ExceptionText>\s*(.*?)\s*</~s', $r['body'], $m)) {
        return "HTTP {$r['status']} errore: " . mb_substr(trim($m[1]), 0, 120);
    }
    return "HTTP {$r['status']} risposta inattesa: " . mb_substr(preg_replace('/\s+/', ' ', strip_tags($r['body'])), 0, 120);
}

$profiles = [
    'come curl'                  => ['ua' => 'curl/8.9.1', 'accept' => '*/*', 'lang' => 'it'],
    'come curl, senza lingua'    => ['ua' => 'curl/8.9.1', 'accept' => '*/*', 'lang' => null],
    'come il programma'          => ['ua' => 'Mozilla/5.0 (compatible; GeoWebCrawler/0.1)',
                                     'accept' => 'application/xml, text/xml;q=0.9, application/json;q=0.9, text/html;q=0.8, */*;q=0.7',
                                     'lang' => 'it-IT,it;q=0.9,en;q=0.8'],
    'programma con lingua "it"'  => ['ua' => 'Mozilla/5.0 (compatible; GeoWebCrawler/0.1)',
                                     'accept' => 'application/xml, text/xml;q=0.9, application/json;q=0.9, text/html;q=0.8, */*;q=0.7',
                                     'lang' => 'it'],
    'programma con Accept */*'   => ['ua' => 'Mozilla/5.0 (compatible; GeoWebCrawler/0.1)', 'accept' => '*/*',
                                     'lang' => 'it-IT,it;q=0.9,en;q=0.8'],
    'nuovo User-Agent GWC'       => ['ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GWC/0.1',
                                     'accept' => 'application/xml, text/xml;q=0.9, application/json;q=0.9, text/html;q=0.8, */*;q=0.7',
                                     'lang' => 'it-IT,it;q=0.9,en;q=0.8'],
    'browser'                    => ['ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
                                     'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                                     'lang' => 'it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7'],
];

$post = '<?xml version="1.0" encoding="UTF-8"?><csw:GetRecords xmlns:csw="http://www.opengis.net/cat/csw/2.0.2" '
      . 'service="CSW" version="2.0.2" resultType="results" startPosition="1" maxRecords="3" '
      . 'outputSchema="http://www.isotc211.org/2005/gmd"><csw:Query typeNames="csw:Record">'
      . '<csw:ElementSetName>full</csw:ElementSetName></csw:Query></csw:GetRecords>';

echo "Catalogo: $url\n\n";
foreach ($profiles as $name => $o) {
    foreach ([true, false] as $session) {
        $jar = $session ? tempnam(sys_get_temp_dir(), 'gwc') : null;
        $target = $url;
        $info = '';
        if ($session) {
            $c = call("$url?$caps", $o, $ca, $jar);
            $cookies = preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)([^\r\n]*)/mi', $c['head'], $m, PREG_SET_ORDER)
                ? implode(', ', array_map(fn($x) => $x[1] . (stripos($x[2], 'secure') !== false ? ' [Secure]' : ''), $m)) : 'nessuno';
            $info = "    GetCapabilities: HTTP {$c['status']}, cookie: $cookies"
                  . ($c['final'] !== "$url?$caps" ? ", reindirizzato a {$c['final']}" : '') . "\n";
            // se GetCapabilities e' stato reindirizzato (es. da http a https) si usa il nuovo indirizzo
            $target = preg_replace('~\?.*$~', '', $c['final']) ?: $url;
        }
        $label = sprintf('%-28s %s', $name, $session ? 'con sessione' : 'senza sessione');
        echo "$label\n$info";
        echo '    GET:           ' . verdict(call("$target?$query", $o, $ca, $jar)) . "\n";
        echo '    GET + filtro:  ' . verdict(call("$target?$query$filter", $o, $ca, $jar)) . "\n";
        echo '    POST:          ' . verdict(call($target, $o, $ca, $jar, $post)) . "\n\n";
        if ($jar) {
            @unlink($jar);
        }
    }
}
