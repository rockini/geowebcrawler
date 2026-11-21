<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

/** Calcolo del riquadro [ovest, sud, est, nord] da geometrie in vari formati (sempre in WGS84). */
final class Geo
{
    public static function bboxOfGeoJson(array $g): ?array
    {
        if (isset($g['bbox']) && is_array($g['bbox']) && count($g['bbox']) >= 4) {
            $b = $g['bbox'];
            return count($b) === 6 ? Xml::bbox((float) $b[0], (float) $b[1], (float) $b[3], (float) $b[4])
                                   : Xml::bbox((float) $b[0], (float) $b[1], (float) $b[2], (float) $b[3]);
        }
        return self::fromPairs(self::coordinatesOf($g));
    }

    /** POLYGON((...)), MULTIPOLYGON, POINT, anche con prefisso "<http://...crs84> " o "SRID=4326;" */
    public static function bboxOfWkt(string $wkt): ?array
    {
        $wkt = preg_replace('~^\s*(<[^>]+>|SRID=\d+;)\s*~i', '', $wkt);
        if (!preg_match('~^\s*(MULTI)?(POLYGON|POINT|LINESTRING|ENVELOPE)~i', $wkt, $m)) {
            return null;
        }
        preg_match_all('~(-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)\s+(-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)~', $wkt, $pairs, PREG_SET_ORDER);
        if (strtoupper($m[2]) === 'ENVELOPE') {   // ENVELOPE(ovest, est, nord, sud)
            preg_match_all('~-?\d+(?:\.\d+)?~', $wkt, $n);
            return count($n[0]) === 4 ? Xml::bbox((float) $n[0][0], (float) $n[0][3], (float) $n[0][1], (float) $n[0][2]) : null;
        }
        return self::fromPairs(array_map(fn($p) => [(float) $p[1], (float) $p[2]], $pairs));
    }

    /** gml:Envelope (lowerCorner/upperCorner) o gml:Polygon (posList); con EPSG:4326 l'ordine e' lat lon. */
    public static function bboxOfGml(string $gml): ?array
    {
        $latFirst = (bool) preg_match('~srsName="[^"]*(?:EPSG::4326|EPSG/0/4326)"~i', $gml);
        $nums = [];
        if (preg_match('~lowerCorner>([^<]+)<.*upperCorner>([^<]+)<~s', $gml, $m)) {
            $nums = array_merge(preg_split('/\s+/', trim($m[1])), preg_split('/\s+/', trim($m[2])));
        } elseif (preg_match_all('~(?:posList|pos|coordinates)[^>]*>([^<]+)<~', $gml, $m)) {
            $nums = preg_split('/[\s,]+/', trim(implode(' ', $m[1])));
        }
        $pairs = [];
        for ($i = 0; $i + 1 < count($nums); $i += 2) {
            $a = (float) $nums[$i];
            $b = (float) $nums[$i + 1];
            $pairs[] = $latFirst ? [$b, $a] : [$a, $b];
        }
        return self::fromPairs($pairs);
    }

    private static function coordinatesOf(array $g): array
    {
        if (isset($g['type']) && $g['type'] === 'FeatureCollection') {
            $out = [];
            foreach ($g['features'] ?? [] as $f) {
                $out = array_merge($out, self::coordinatesOf($f));
            }
            return $out;
        }
        if (isset($g['type']) && $g['type'] === 'Feature') {
            return self::coordinatesOf($g['geometry'] ?? []);
        }
        if (isset($g['type']) && $g['type'] === 'GeometryCollection') {
            $out = [];
            foreach ($g['geometries'] ?? [] as $x) {
                $out = array_merge($out, self::coordinatesOf($x));
            }
            return $out;
        }
        $pairs = [];
        $walk = function ($c) use (&$walk, &$pairs) {
            if (is_array($c) && isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
                $pairs[] = [(float) $c[0], (float) $c[1]];
            } elseif (is_array($c)) {
                foreach ($c as $x) {
                    $walk($x);
                }
            }
        };
        $walk($g['coordinates'] ?? []);
        return $pairs;
    }

    private static function fromPairs(array $pairs): ?array
    {
        if (!$pairs) {
            return null;
        }
        $xs = array_column($pairs, 0);
        $ys = array_column($pairs, 1);
        return Xml::bbox(min($xs), min($ys), max($xs), max($ys));
    }
}
