<?php
declare(strict_types=1);

namespace GeoWebCrawler\Repository;

use GeoWebCrawler\Harvest\CswHarvester;
use GeoWebCrawler\Harvest\OgcCapabilitiesHarvester;
use PDO;

/**
 * Scoperta di nuove sorgenti: i servizi OGC (WMS, WFS, WCS, WMTS) e i cataloghi CSW citati nei
 * punti di accesso delle risorse raccolte, che non sono ancora sorgenti, vengono proposti.
 * Le proposte sono sorgenti disattivate con discovery_status = 'proposed', da approvare o
 * ignorare nell'interfaccia (pagina Sorgenti > Proposte).
 */
final class SourceDiscovery
{
    private const TYPES = ['wms', 'wfs', 'wcs', 'wmts', 'csw'];

    public function __construct(private PDO $db) {}

    /** @return int numero di nuove proposte */
    public function discover(): int
    {
        $ogcType = (bool) $this->db->query("SELECT 1 FROM gwc.source_types WHERE code = 'ogc_service'")->fetchColumn();
        $types = $ogcType ? self::TYPES : ['csw'];
        $in = "'" . implode("','", $types) . "'";

        // servizi gia' presenti come sorgenti: chiave del servizio e tipo (* = tutti i tipi)
        $existing = [];
        foreach ($this->db->query("SELECT url, gwc.service_key(url) AS k FROM gwc.sources") as $s) {
            $existing[$s['k']][] = self::serviceParam($s['url']) ?? '*';
        }

        $rows = $this->db->query(
            "SELECT e.service_key, e.endpoint_type, min(e.url) AS sample, count(DISTINCT e.resource_id) AS n
               FROM gwc.endpoints e
               JOIN gwc.resources r ON r.id = e.resource_id AND r.status IN ('active','pending')
              WHERE e.service_key IS NOT NULL AND e.endpoint_type IN ($in) AND e.url NOT LIKE '%{%'
              GROUP BY 1, 2"
        )->fetchAll();

        $ins = $this->db->prepare(
            "INSERT INTO gwc.sources (name, source_type, url, origin, enabled, discovery_status, discovered_count)
             VALUES (?, ?, ?, 'discovered', false, 'proposed', ?)
             ON CONFLICT (url_key) DO UPDATE SET discovered_count = EXCLUDED.discovered_count
              WHERE gwc.sources.origin = 'discovered'
             RETURNING (xmax = 0) AS inserted");
        $new = 0;
        foreach ($rows as $r) {
            $type = $r['endpoint_type'];
            $known = $existing[$r['service_key']] ?? [];
            if (in_array('*', $known, true) || in_array($type, $known, true)) {
                continue;
            }
            if ($type === 'csw') {
                $url = CswHarvester::endpointUrl($r['sample']);
                $sourceType = 'csw';
            } else {
                $base = OgcCapabilitiesHarvester::baseUrl($r['sample']);
                $url = $base . (str_contains($base, '?') ? '&' : '?') . 'SERVICE=' . strtoupper($type) . '&REQUEST=GetCapabilities';
                $sourceType = 'ogc_service';
            }
            $ins->execute([self::name($type, $r['sample']), $sourceType, $url, (int) $r['n']]);
            if ($ins->fetchColumn()) {
                $new++;
            }
            $existing[$r['service_key']][] = $type;
        }
        return $new;
    }

    /** es. "WMS wms.pcn.minambiente.it/ogc - Rete_ferroviaria" */
    public static function name(string $type, string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $name = strtoupper($type) . ' ' . $host . $path;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $q = array_change_key_case($q, CASE_LOWER);
        if (isset($q['map']) && is_string($q['map'])) {
            $name .= ' - ' . preg_replace('~\.map$~i', '', basename($q['map']));
        }
        return mb_substr($name, 0, 200);
    }

    private static function serviceParam(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $q = array_change_key_case($q, CASE_LOWER);
        return isset($q['service']) && is_string($q['service']) && $q['service'] !== '' ? strtolower($q['service']) : null;
    }
}
