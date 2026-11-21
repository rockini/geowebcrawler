<?php
declare(strict_types=1);

namespace GeoWebCrawler\Repository;

use PDO;

/**
 * Raggruppa le coppie di possibili duplicati in gruppi (componenti connesse): se A~B e B~C,
 * A, B e C sono nello stesso gruppo e si decide una volta sola quale risorsa tenere.
 */
final class DuplicateGroups
{
    public function __construct(private PDO $db) {}

    /**
     * @return array<int, array{ids:int[], pairs:array<int,array>, certain:bool, score:float}>
     *         gruppi ordinati: prima quelli certi (solo stesso identificativo), poi per somiglianza
     */
    public function all(): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $pairs = $this->db->query("SELECT candidate_id, a_id, b_id, reason, score FROM gwc.v_duplicates_pending")
                          ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pairs as $p) {
            $a = (int) $p['a_id'];
            $b = (int) $p['b_id'];
            $parent[$a] ??= $a;
            $parent[$b] ??= $b;
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        }
        $groups = [];
        foreach ($pairs as $p) {
            $g = $find((int) $p['a_id']);
            $groups[$g]['pairs'][] = $p;
            $groups[$g]['ids'][(int) $p['a_id']] = true;
            $groups[$g]['ids'][(int) $p['b_id']] = true;
        }
        $out = [];
        foreach ($groups as $g) {
            $ids = array_keys($g['ids']);
            sort($ids);
            $out[] = [
                'ids'     => $ids,
                'pairs'   => $g['pairs'],
                'certain' => !array_filter($g['pairs'], fn($p) => $p['reason'] !== 'same_external_id'),
                'score'   => max(array_map(fn($p) => (float) $p['score'], $g['pairs'])),
            ];
        }
        usort($out, fn($x, $y) => [$y['certain'], $y['score'], count($y['ids'])] <=> [$x['certain'], $x['score'], count($x['ids'])]);
        return $out;
    }

    /** Risorsa da tenere in un gruppo: piu' punti di accesso, metadati piu' recenti, piu' sorgenti. */
    public function bestOf(array $ids): int
    {
        $in = implode(',', array_map('intval', $ids));
        return (int) $this->db->query(
            "SELECT r.id FROM gwc.resources r
              WHERE r.id IN ($in) AND r.status <> 'duplicate'
              ORDER BY (SELECT count(*) FROM gwc.endpoints e WHERE e.resource_id = r.id) DESC,
                       r.metadata_date DESC NULLS LAST,
                       (SELECT count(*) FROM gwc.resource_sources s WHERE s.resource_id = r.id) DESC,
                       r.id
              LIMIT 1")->fetchColumn();
    }

    /** Unisce al primo id tutti gli altri del gruppo. @return int risorse unite */
    public function merge(int $keep, array $ids, ?string $note = null): int
    {
        $st = $this->db->prepare("SELECT gwc.merge_resources(?, ?, ?)");
        $ok = $this->db->prepare("SELECT 1 FROM gwc.resources WHERE id = ? AND status <> 'duplicate'");
        $n = 0;
        $own = !$this->db->inTransaction();
        $own && $this->db->beginTransaction();
        try {
            foreach ($ids as $id) {
                $id = (int) $id;
                $ok->execute([$id]);
                if ($id !== $keep && $ok->fetchColumn()) {
                    $st->execute([$keep, $id, $note]);
                    $n++;
                }
            }
            $own && $this->db->commit();
        } catch (\Throwable $e) {
            $own && $this->db->rollBack();
            throw $e;
        }
        return $n;
    }

    /** Le risorse del gruppo sono tutte diverse: le coppie non verranno piu' proposte. */
    public function reject(array $ids, ?string $note = null): int
    {
        $arr = '{' . implode(',', array_map('intval', $ids)) . '}';
        $st = $this->db->prepare("UPDATE gwc.duplicate_candidates SET status = 'rejected', decided_at = now(), note = COALESCE(?, note)
                                   WHERE status = 'pending' AND resource_a = ANY (CAST(? AS bigint[])) AND resource_b = ANY (CAST(? AS bigint[]))");
        $st->execute([$note, $arr, $arr]);
        return $st->rowCount();
    }

    /** Unisce automaticamente i gruppi certi (stesso identificativo). @return array{0:int,1:int} gruppi, risorse unite */
    public function autoMerge(): array
    {
        $groups = 0;
        $merged = 0;
        foreach ($this->all() as $g) {
            if (!$g['certain']) {
                continue;
            }
            $keep = $this->bestOf($g['ids']);
            if ($keep > 0) {
                $merged += $this->merge($keep, $g['ids'], 'unione automatica: stesso identificativo');
                $groups++;
            }
        }
        return [$groups, $merged];
    }
}
