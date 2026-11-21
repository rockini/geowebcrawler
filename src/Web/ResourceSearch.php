<?php
declare(strict_types=1);

namespace GeoWebCrawler\Web;

use PDO;

/**
 * Ricerca delle risorse con filtri combinabili e faccette (conteggi per ogni valore dei filtri).
 * Filtri: q (testo), type, theme, org (ente principale), format, ep (tipo di accesso), license,
 * source, year, status, bbox (ovest,sud,est,nord: risorse la cui estensione interseca il riquadro),
 * chk (verifica dei servizi: ok = almeno un punto di accesso funziona, error = nessuno funziona, never = mai verificati).
 */
final class ResourceSearch
{
    public const FILTERS = ['q', 'type', 'theme', 'org', 'format', 'ep', 'license', 'source', 'year', 'status', 'bbox', 'chk'];

    /** @var array<string,string> */
    public array $f = [];
    private string $where = '';
    private array $args = [];

    public function __construct(private PDO $db, array $input)
    {
        foreach (self::FILTERS as $k) {
            $this->f[$k] = trim((string) ($input[$k] ?? ''));
        }
        if ($this->f['status'] === '') {
            $this->f['status'] = 'active';
        }
        $this->build();
    }

    /** Filtri attivi, per costruire i link (senza quelli vuoti e senza lo stato predefinito). */
    public function params(array $override = []): array
    {
        $p = array_merge($this->f, $override);
        if (($p['status'] ?? '') === 'active') {
            unset($p['status']);
        }
        return array_filter($p, fn($v) => $v !== '' && $v !== null);
    }

    public function bbox(): ?array
    {
        $b = array_map('floatval', explode(',', $this->f['bbox']));
        return count($b) === 4 && $b[0] < $b[2] && $b[1] < $b[3] ? $b : null;
    }

    private function build(): void
    {
        $w = [];
        $a = [];
        $f = $this->f;
        if ($f['q'] !== '') {
            $w[] = "(r.search_vector @@ plainto_tsquery('simple', :q) OR r.title ILIKE :qlike)";
            $a[':q'] = $f['q'];
            $a[':qlike'] = '%' . $f['q'] . '%';
        }
        if ($f['type'] !== '') {
            $w[] = 'r.resource_type = :type';
            $a[':type'] = $f['type'];
        }
        $w[] = match ($f['status']) {
            'all'    => 'TRUE',
            'active' => "r.status IN ('active','pending')",
            default  => 'r.status = :status',
        };
        if (!in_array($f['status'], ['all', 'active'], true)) {
            $a[':status'] = $f['status'];
        }
        if ($f['theme'] !== '') {
            $w[] = $f['theme'] === '-' ? "r.themes = '{}'" : 'r.themes @> ARRAY[CAST(:theme AS text)]';
            if ($f['theme'] !== '-') {
                $a[':theme'] = $f['theme'];
            }
        }
        if ($f['org'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM gwc.resource_parties p JOIN gwc.organizations o ON o.id = p.organization_id
                             WHERE p.resource_id = r.id AND COALESCE(o.canonical_id, o.id) = :org)';
            $a[':org'] = (int) $f['org'];
        }
        if ($f['format'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM gwc.endpoints e JOIN gwc.formats fm ON fm.id = e.format_id
                             WHERE e.resource_id = r.id AND fm.code = :format)';
            $a[':format'] = $f['format'];
        }
        if ($f['ep'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM gwc.endpoints e WHERE e.resource_id = r.id AND e.endpoint_type = :ep)';
            $a[':ep'] = $f['ep'];
        }
        if ($f['license'] !== '') {
            $w[] = $f['license'] === '-' ? 'r.license_id IS NULL'
                 : 'r.license_id = (SELECT id FROM gwc.licenses WHERE code = :license)';
            if ($f['license'] !== '-') {
                $a[':license'] = $f['license'];
            }
        }
        if ($f['source'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM gwc.resource_sources rs WHERE rs.resource_id = r.id AND rs.source_id = :source)';
            $a[':source'] = (int) $f['source'];
        }
        if ($f['year'] !== '') {
            $w[] = $f['year'] === '-' ? 'COALESCE(r.date_revised, r.date_published, r.date_created) IS NULL'
                 : 'extract(year FROM COALESCE(r.date_revised, r.date_published, r.date_created)) = :year';
            if ($f['year'] !== '-') {
                $a[':year'] = (int) $f['year'];
            }
        }
        $w[] = match ($f['chk']) {
            'ok'    => "EXISTS (SELECT 1 FROM gwc.endpoints e WHERE e.resource_id = r.id AND e.last_check_status IN ('ok','warn'))",
            'error' => "EXISTS (SELECT 1 FROM gwc.endpoints e WHERE e.resource_id = r.id AND e.last_check_status = 'error')
                        AND NOT EXISTS (SELECT 1 FROM gwc.endpoints e WHERE e.resource_id = r.id AND e.last_check_status IN ('ok','warn'))",
            'never' => "NOT EXISTS (SELECT 1 FROM gwc.endpoints e WHERE e.resource_id = r.id AND e.last_check_at IS NOT NULL)",
            default => 'TRUE',
        };
        if ($b = $this->bbox()) {
            $w[] = 'r.bbox && ST_MakeEnvelope(:bw, :bs, :be, :bn, 4326)';
            [$a[':bw'], $a[':bs'], $a[':be'], $a[':bn']] = $b;
        }
        $this->where = 'WHERE ' . implode(' AND ', $w);
        $this->args = $a;
    }

    private function query(string $sql): array
    {
        $st = $this->db->prepare($sql);
        foreach ($this->args as $k => $v) {
            $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->execute();
        return $st->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->query("SELECT count(*) AS n FROM gwc.resources r {$this->where}")[0]['n'];
    }

    public function rows(int $limit, int $offset): array
    {
        return $this->query("SELECT v.*, ST_AsGeoJSON(r.bbox, 4) AS bbox_json
                               FROM gwc.resources r JOIN gwc.v_resources v ON v.id = r.id
                               {$this->where}
                              ORDER BY r.last_seen_at DESC, r.id DESC LIMIT $limit OFFSET $offset");
    }

    /** @return array<string, array<int, array{v:string, label:string, n:int}>> conteggi per ogni filtro */
    public function facets(): array
    {
        $w = $this->where;
        $out = [];
        $out['type'] = $this->query("SELECT r.resource_type AS v, r.resource_type AS label, count(*) AS n
                                       FROM gwc.resources r $w GROUP BY 1 ORDER BY 3 DESC");
        $out['theme'] = $this->query("SELECT COALESCE(t.code, '-') AS v, COALESCE(t.label, 'senza tema') AS label, x.n
                                        FROM (SELECT COALESCE(th, '-') AS code, count(*) AS n
                                                FROM gwc.resources r LEFT JOIN LATERAL unnest(NULLIF(r.themes, '{}')) th ON true
                                                $w GROUP BY 1) x
                                        LEFT JOIN gwc.themes t ON t.code = x.code ORDER BY x.n DESC");
        $out['org'] = $this->query("SELECT COALESCE(o.canonical_id, o.id)::text AS v, COALESCE(m.name, o.name) AS label,
                                           count(DISTINCT r.id) AS n
                                      FROM gwc.resources r
                                      JOIN gwc.resource_parties p ON p.resource_id = r.id
                                      JOIN gwc.organizations o ON o.id = p.organization_id
                                      LEFT JOIN gwc.organizations m ON m.id = o.canonical_id
                                      $w GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 20");
        $out['format'] = $this->query("SELECT fm.code AS v, fm.label, count(DISTINCT r.id) AS n
                                         FROM gwc.resources r JOIN gwc.endpoints e ON e.resource_id = r.id
                                         JOIN gwc.formats fm ON fm.id = e.format_id
                                         $w GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 20");
        $out['ep'] = $this->query("SELECT e.endpoint_type AS v, t.label, count(DISTINCT r.id) AS n
                                     FROM gwc.resources r JOIN gwc.endpoints e ON e.resource_id = r.id
                                     JOIN gwc.endpoint_types t ON t.code = e.endpoint_type
                                     $w GROUP BY 1, 2 ORDER BY 3 DESC");
        $out['license'] = $this->query("SELECT COALESCE(l.code, '-') AS v, COALESCE(l.label, 'non riconosciuta') AS label, count(*) AS n
                                          FROM gwc.resources r LEFT JOIN gwc.licenses l ON l.id = r.license_id
                                          $w GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 20");
        $out['source'] = $this->query("SELECT s.id::text AS v, s.name AS label, count(DISTINCT r.id) AS n
                                         FROM gwc.resources r JOIN gwc.resource_sources rs ON rs.resource_id = r.id
                                         JOIN gwc.sources s ON s.id = rs.source_id
                                         $w GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 20");
        $out['year'] = $this->query("SELECT COALESCE(y::text, '-') AS v, COALESCE(y::text, 'senza data') AS label, count(*) AS n
                                       FROM (SELECT extract(year FROM COALESCE(r.date_revised, r.date_published, r.date_created))::int AS y
                                               FROM gwc.resources r $w) x
                                      GROUP BY 1, 2 ORDER BY 1 DESC LIMIT 25");
        $out['chk'] = $this->query("SELECT v, label, count(*) AS n FROM (
                                        SELECT CASE WHEN bool_or(e.last_check_status IN ('ok','warn')) THEN 'ok'
                                                    WHEN bool_or(e.last_check_status = 'error') THEN 'error' ELSE 'never' END AS v,
                                               CASE WHEN bool_or(e.last_check_status IN ('ok','warn')) THEN 'almeno un accesso funziona'
                                                    WHEN bool_or(e.last_check_status = 'error') THEN 'nessun accesso funziona'
                                                    ELSE 'mai verificate' END AS label
                                          FROM gwc.resources r LEFT JOIN gwc.endpoints e ON e.resource_id = r.id
                                          $w GROUP BY r.id) x GROUP BY 1, 2 ORDER BY 3 DESC");
        $out['status'] = $this->withoutStatus($w);
        return $out;
    }

    private function withoutStatus(string $w): array
    {
        $args = $this->args;
        unset($args[':status']);
        $st = $this->db->prepare("SELECT r.status AS v, r.status AS label, count(*) AS n FROM gwc.resources r
                                  " . str_replace(["r.status IN ('active','pending')", 'r.status = :status'], 'TRUE', $w)
                                  . " GROUP BY 1 ORDER BY 3 DESC");
        foreach ($args as $k => $v) {
            $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->execute();
        return $st->fetchAll();
    }
}
