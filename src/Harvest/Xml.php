<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use DOMDocument;
use DOMNode;
use DOMXPath;

/** Funzioni di supporto per leggere XML con XPath. */
final class Xml
{
    public const NS = [
        'csw'   => 'http://www.opengis.net/cat/csw/2.0.2',
        'gmd'   => 'http://www.isotc211.org/2005/gmd',
        'gco'   => 'http://www.isotc211.org/2005/gco',
        'gmx'   => 'http://www.isotc211.org/2005/gmx',
        'srv'   => 'http://www.isotc211.org/2005/srv',
        'gml'   => 'http://www.opengis.net/gml',
        'gml32' => 'http://www.opengis.net/gml/3.2',
        'xlink' => 'http://www.w3.org/1999/xlink',
        'dc'    => 'http://purl.org/dc/elements/1.1/',
        'dct'   => 'http://purl.org/dc/terms/',
        'ows'   => 'http://www.opengis.net/ows',
    ];

    public static function load(string $xml): ?DOMDocument
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET: nessun accesso alla rete durante il parsing (sicurezza)
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        foreach (self::NS as $p => $uri) {
            $xp->registerNamespace($p, $uri);
        }
        return $xp;
    }

    /** Primo valore testuale non vuoto trovato dall'espressione. */
    public static function text(DOMXPath $xp, string $expr, ?DOMNode $ctx = null): ?string
    {
        $nodes = $xp->query($expr, $ctx);
        if ($nodes !== false) {
            foreach ($nodes as $n) {
                $v = trim(preg_replace('/\s+/u', ' ', $n->textContent));
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return null;
    }

    /** Tutti i valori testuali non vuoti, senza ripetizioni. */
    public static function texts(DOMXPath $xp, string $expr, ?DOMNode $ctx = null): array
    {
        $out = [];
        $nodes = $xp->query($expr, $ctx);
        if ($nodes !== false) {
            foreach ($nodes as $n) {
                $v = trim(preg_replace('/\s+/u', ' ', $n->textContent));
                if ($v !== '') {
                    $out[$v] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** Normalizza una data ISO (anche con orario) in YYYY-MM-DD; null se non riconosciuta. */
    public static function date(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "$m[1]-$m[2]-$m[3]";
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $v, $m)) {
            return "$m[1]-$m[2]-01";
        }
        if (preg_match('/^(\d{4})$/', $v, $m)) {
            return "$m[1]-01-01";
        }
        return null;
    }

    /** Estrae i codici EPSG da stringhe come "EPSG:3003", "urn:ogc:def:crs:EPSG::4326", ".../EPSG/0/32632". */
    public static function epsg(array $values): array
    {
        $codes = [];
        foreach ($values as $v) {
            if (preg_match('~epsg\D{0,4}(?:0/)?(\d{4,6})~i', $v, $m) || preg_match('~^\s*(\d{4,6})\s*$~', $v, $m)) {
                $codes[(int) $m[1]] = true;
            }
        }
        return array_keys($codes);
    }

    /** Valida un riquadro [ovest, sud, est, nord] in gradi. */
    public static function bbox(?float $w, ?float $s, ?float $e, ?float $n): ?array
    {
        if ($w === null || $s === null || $e === null || $n === null) {
            return null;
        }
        if ($w < -180 || $e > 180 || $s < -90 || $n > 90 || $w > $e || $s > $n) {
            return null;
        }
        return [$w, $s, $e, $n];
    }

    public static function num(?string $v): ?float
    {
        return $v !== null && is_numeric($v) ? (float) $v : null;
    }
}
