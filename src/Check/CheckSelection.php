<?php
declare(strict_types=1);

namespace GeoWebCrawler\Check;

use PDO;

/**
 * Scelta di cosa verificare. Si filtrano i punti di accesso delle risorse attive e si raggruppano per
 * "obiettivo" (check_key): un servizio OGC (tutti i suoi layer insieme) oppure un singolo indirizzo.
 *
 * Filtri: type (tipo di accesso, anche piu' di uno separati da virgola), source, org, theme, q (titolo
 * della risorsa), host (parte dell'indirizzo), status (never | error | warn | ok | problems | any),
 * older (giorni dall'ultima verifica), resource (id), keys (elenco esplicito di obiettivi).
 */
final class CheckSelection
{
    public const FILTERS = ['type', 'source', 'org', 'theme', 'q', 'host', 'status', 'older', 'resource', 'keys'];

    public array $f = [];

    public function __construct(private PDO $db, array $input)
    {
        foreach (self::FILTERS as $k) {
            $v = $input[$k] ?? '';
            $this->f[$k] = is_array($v) ? array_values(array_filter(array_map('strval', $v))) : trim((string) $v);
        }
    }

    /** Filtri non vuoti (per link, salvataggio nel lotto e descrizione). */
    public function params(): array
    {
        return array_filter($this->f, fn($v) => $v !== '' && $v !== []);
    }

    public function describe(): string
    {
        $labels = ['type' => 'tipo', 'source' => 'sorgente', 'org' => 'ente', 'theme' => 'tema', 'q' => 'titolo', 'host' => 'indirizzo',
                   'status' => 'stato', 'older' => 'verificati da piu\' di giorni', 'resource' => 'risorsa'];
        $out = [];
        foreach ($this->params() as $k => $v) {
            $out[] = $k === 'keys' ? count((array) $v) . ' scelti a mano' : ($labels[$k] ?? $k) . ': ' . (is_array($v) ? implode(', ', $v) : $v);
        }
        return $out ? implode('; ', $out) : 'tutti';
    }

    /** @return array{0:string,1:array} condizioni sui punti di accesso */
    private function endpointWhere(): array
    {
        $f = $this->f;
        $w = ["r.status IN ('active','pending')"];
        $a = [];
        if ($f['type'] !== '') {
            $types = array_values(array_filter(array_map('trim', explode(',', $f['type']))));
            $ph = [];
            foreach ($types as $i => $t) {
                $ph[] = ":t$i";
                $a[":t$i"] = $t;
            }
            $w[] = 'e.endpoint_type IN (' . implode(',', $ph) . ')';
        }
        if ($f['source'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM gwc.resource_sources rs WHERE rs.resource_id = r.id AND rs.source_id = :source)';
            $a[':source'] = (int) $f['source'];
        }
        if ($f['org'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM gwc.resource_parties p JOIN gwc.organizations o ON o.id = p.organization_id
                             WHERE p.resource_id = r.id AND COALESCE(o.canonical_id, o.id) = :org)';
            $a[':org'] = (int) $f['org'];
        }
        if ($f['theme'] !== '') {
            $w[] = 'r.themes @> ARRAY[CAST(:theme AS text)]';
            $a[':theme'] = $f['theme'];
        }
        if ($f['q'] !== '') {
            $w[] = 'r.title ILIKE :q';
            $a[':q'] = '%' . $f['q'] . '%';
        }
        if ($f['host'] !== '') {
            $w[] = 'e.url ILIKE :host';
            $a[':host'] = '%' . $f['host'] . '%';
        }
        if ($f['resource'] !== '') {
            $w[] = 'e.resource_id = :resource';
            $a[':resource'] = (int) $f['resource'];
        }
        if ($f['keys'] !== [] && $f['keys'] !== '') {
            $w[] = 'e.check_key = ANY (CAST(:keys AS text[]))';
            $a[':keys'] = self::pgArray((array) $f['keys']);
        }
        return [implode(' AND ', $w), $a];
    }

    /** @return array{0:string,1:array} condizioni sull'esito degli obiettivi (alias t) */
    private function targetWhere(): array
    {
        $w = [];
        $a = [];
        $w[] = match ($this->f['status']) {
            'never'    => 't.last_check_at IS NULL',
            'error'    => "t.last_status = 'error'",
            'warn'     => "t.last_status = 'warn'",
            'ok'       => "t.last_status = 'ok'",
            'problems' => "t.last_status IN ('warn','error')",
            default    => 'TRUE',
        };
        if ($this->f['older'] !== '' && (int) $this->f['older'] > 0) {
            $w[] = '(t.last_check_at IS NULL OR t.last_check_at < now() - make_interval(days => :older))';
            $a[':older'] = (int) $this->f['older'];
        }
        return [implode(' AND ', $w), $a];
    }

    private function baseSql(): array
    {
        [$ew, $ea] = $this->endpointWhere();
        [$tw, $ta] = $this->targetWhere();
        $sql = "WITH sel AS (
                    SELECT e.check_key,
                           min(e.endpoint_type) AS endpoint_type,
                           (array_agg(e.url ORDER BY e.id))[1] AS url,
                           count(*) AS n_endpoints,
                           count(DISTINCT e.resource_id) AS n_resources,
                           count(*) FILTER (WHERE e.last_check_status = 'warn') AS n_warn_endpoints,
                           (array_agg(r.title ORDER BY e.id))[1] AS sample_title
                      FROM gwc.endpoints e JOIN gwc.resources r ON r.id = e.resource_id
                     WHERE $ew
                     GROUP BY e.check_key)
                SELECT sel.*, t.last_check_at, t.last_status, t.response_ms, t.http_status, t.service_title,
                       t.service_version, t.n_layers, t.note, t.n_checks, t.n_failures, t.last_ok_at
                  FROM sel LEFT JOIN gwc.check_targets t ON t.check_key = sel.check_key
                 WHERE $tw";
        return [$sql, $ea + $ta];
    }

    private function run(string $sql, array $args): array
    {
        $st = $this->db->prepare($sql);
        foreach ($args as $k => $v) {
            $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{targets:int, endpoints:int, resources:int} */
    public function count(): array
    {
        [$sql, $a] = $this->baseSql();
        $r = $this->run("SELECT count(*) AS targets, COALESCE(sum(n_endpoints), 0) AS endpoints, COALESCE(sum(n_resources), 0) AS resources FROM ($sql) x", $a)[0];
        return array_map('intval', $r);
    }

    /** Obiettivi selezionati. $order: 'priority' (prima i mai verificati e i piu' vecchi) o 'recent'. */
    public function targets(int $limit, int $offset = 0, string $order = 'priority'): array
    {
        [$sql, $a] = $this->baseSql();
        $ord = $order === 'recent' ? 'last_check_at DESC NULLS LAST, check_key'
             : 'last_check_at ASC NULLS FIRST, n_resources DESC, check_key';
        return $this->run("$sql ORDER BY $ord LIMIT $limit OFFSET $offset", $a);
    }

    /** Punti di accesso di un obiettivo (per capire quali layer cercare). */
    public static function endpointsOf(PDO $db, array $keys): array
    {
        if (!$keys) {
            return [];
        }
        $st = $db->prepare("SELECT e.id, e.check_key, e.endpoint_type, e.url, e.layer_name, e.resource_id
                              FROM gwc.endpoints e JOIN gwc.resources r ON r.id = e.resource_id
                             WHERE e.check_key = ANY (CAST(? AS text[])) AND r.status IN ('active','pending')");
        $st->execute([self::pgArray($keys)]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $out[$e['check_key']][] = $e;
        }
        return $out;
    }

    public static function pgArray(array $values): string
    {
        return '{' . implode(',', array_map(fn($v) => '"' . addcslashes((string) $v, '"\\') . '"', $values)) . '}';
    }
}
