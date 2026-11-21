<?php
declare(strict_types=1);

namespace GeoWebCrawler\Repository;

use PDO;

/**
 * Normalizzazione degli enti: le varianti dello stesso ente ("REGIONE PIEMONTE - Settore X",
 * "Regione Piemonte (Direzione Y)") puntano a un ente principale (canonical_id).
 * Nelle ricerche e nelle faccette si usa l'ente principale; i nomi originali restano nelle schede.
 */
final class Organizations
{
    public function __construct(private PDO $db) {}

    /** Gruppi di enti principali con lo stesso nome di base (vedi gwc.org_group_key). */
    public function suggestions(int $limit = 100): array
    {
        $rows = $this->db->query(
            "WITH o AS (
                SELECT o.id, o.name, gwc.org_group_key(o.name) AS gk,
                       (SELECT count(DISTINCT p.resource_id) FROM gwc.resource_parties p
                          JOIN gwc.organizations v ON v.id = p.organization_id
                         WHERE COALESCE(v.canonical_id, v.id) = o.id) AS n
                  FROM gwc.organizations o WHERE o.canonical_id IS NULL)
             SELECT gk, json_agg(json_build_object('id', id, 'name', name, 'n', n) ORDER BY (gwc.normalize_text(name) = gk) DESC, n DESC, length(name), id) AS orgs,
                    sum(n) AS total
               FROM o WHERE gk <> ''
              GROUP BY gk HAVING count(*) > 1
              ORDER BY sum(n) DESC, gk LIMIT " . (int) $limit)->fetchAll();
        foreach ($rows as &$r) {
            $r['orgs'] = json_decode($r['orgs'], true);
        }
        return $rows;
    }

    /** Enti principali con numero di risorse e varianti. */
    public function list(string $q, int $limit, int $offset): array
    {
        $st = $this->db->prepare(
            "SELECT o.id, o.name, o.email, o.url,
                    (SELECT count(DISTINCT p.resource_id) FROM gwc.resource_parties p
                       JOIN gwc.organizations v ON v.id = p.organization_id
                      WHERE COALESCE(v.canonical_id, v.id) = o.id) AS n,
                    (SELECT json_agg(json_build_object('id', v.id, 'name', v.name) ORDER BY v.name)
                       FROM gwc.organizations v WHERE v.canonical_id = o.id) AS variants
               FROM gwc.organizations o
              WHERE o.canonical_id IS NULL
                AND (:q = '' OR o.name_key LIKE '%' || gwc.normalize_text(:q2) || '%'
                     OR EXISTS (SELECT 1 FROM gwc.organizations v WHERE v.canonical_id = o.id
                                   AND v.name_key LIKE '%' || gwc.normalize_text(:q3) || '%'))
              ORDER BY n DESC, o.name LIMIT $limit OFFSET $offset");
        $st->execute([':q' => $q, ':q2' => $q, ':q3' => $q]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['variants'] = json_decode((string) $r['variants'], true) ?: [];
        }
        return $rows;
    }

    public function count(string $q): int
    {
        $st = $this->db->prepare("SELECT count(*) FROM gwc.organizations o WHERE o.canonical_id IS NULL
                                    AND (:q = '' OR o.name_key LIKE '%' || gwc.normalize_text(:q2) || '%')");
        $st->execute([':q' => $q, ':q2' => $q]);
        return (int) $st->fetchColumn();
    }

    /** Gli enti $ids diventano varianti di $main (anche le loro varianti). @return int enti uniti */
    public function merge(int $main, array $ids): int
    {
        // se l'ente scelto e' a sua volta una variante, si usa il suo principale
        $st = $this->db->prepare("SELECT COALESCE(canonical_id, id) FROM gwc.organizations WHERE id = ?");
        $st->execute([$main]);
        $main = (int) $st->fetchColumn();
        if ($main === 0) {
            throw new \InvalidArgumentException('Ente principale inesistente');
        }
        $ids = array_values(array_diff(array_map('intval', $ids), [$main]));
        if (!$ids) {
            return 0;
        }
        $arr = '{' . implode(',', $ids) . '}';
        $st = $this->db->prepare("UPDATE gwc.organizations SET canonical_id = ?
                                   WHERE id <> ? AND (id = ANY (CAST(? AS bigint[])) OR canonical_id = ANY (CAST(? AS bigint[])))");
        $st->execute([$main, $main, $arr, $arr]);
        return $st->rowCount();
    }

    public function unmerge(int $id): void
    {
        $this->db->prepare("UPDATE gwc.organizations SET canonical_id = NULL WHERE id = ?")->execute([$id]);
    }

    /** Applica tutti i suggerimenti: in ogni gruppo l'ente principale e' quello con piu' risorse. @return int enti uniti */
    public function autoMerge(): int
    {
        $n = 0;
        $this->db->beginTransaction();
        try {
            foreach ($this->suggestions(100000) as $g) {
                $ids = array_column($g['orgs'], 'id');
                $n += $this->merge((int) $ids[0], $ids);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $n;
    }
}
