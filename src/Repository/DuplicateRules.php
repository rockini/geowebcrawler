<?php
declare(strict_types=1);

namespace GeoWebCrawler\Repository;

use PDO;

/**
 * Regole per unire o scartare automaticamente le coppie di possibili duplicati.
 *
 * Unione: le coppie che rispettano almeno una delle regole scelte formano gruppi (se A~B e B~C, il gruppo
 * e' A, B, C); in ogni gruppo si tiene la risorsa migliore (vedi DuplicateGroups::bestOf) e le altre vengono
 * unite a quella. I gruppi troppo grandi si saltano: di solito sono catene di risorse solo simili.
 * Scarto: le coppie che rispettano la regola di scarto vengono segnate come "risorse diverse".
 * Prima di applicare si puo' vedere l'anteprima. Le unioni si annullano dalla scheda della risorsa.
 */
final class DuplicateRules
{
    public const MERGE_RULES = [
        'same_id'    => 'Stesso identificativo e stesso tipo',
        'url_title'  => 'Stesso URL di accesso e titolo simile',
        'title_org'  => 'Titolo quasi uguale, stesso ente, stessa area',
        'same_title' => 'Titolo identico, stesso tipo, stessa area (anche con enti diversi)',
    ];

    public const DEFAULTS = [
        'rules'       => ['same_id', 'url_title', 'title_org'],
        'url_sim'     => 0.80,  // somiglianza minima del titolo per la regola url_title
        'title_sim'   => 0.95,  // somiglianza minima del titolo per la regola title_org
        'overlap'     => 0.90,  // sovrapposizione minima delle aree (1 = identiche)
        'max_group'   => 10,    // gruppi piu' grandi di cosi' non si uniscono in automatico
        // scarto: titolo poco simile, enti diversi e aree lontane
        'reject_sim'  => 0.90,
        'reject_overlap' => 0.20,
    ];

    private array $o;

    public function __construct(private PDO $db, array $options = [])
    {
        $o = self::DEFAULTS;
        foreach ($options as $k => $v) {
            if (array_key_exists($k, $o) && $v !== '' && $v !== null) {
                $o[$k] = is_array($o[$k]) ? array_values(array_intersect((array) $v, array_keys(self::MERGE_RULES)))
                                          : (is_int($o[$k]) ? max(2, (int) $v) : max(0.0, min(1.0, (float) $v)));
            }
        }
        $this->o = $o;
    }

    public function options(): array
    {
        return $this->o;
    }

    /** Condizione SQL (su v_duplicate_features f) per una regola di unione. */
    private function ruleSql(string $rule): string
    {
        // valori gia' convertiti in numeri nel costruttore: si scrivono direttamente nella query
        $n = fn(string $k) => sprintf('%.4F', $this->o[$k]);
        $overlap = '(f.bbox_overlap IS NULL OR f.bbox_overlap >= ' . $n('overlap') . ')';
        return match ($rule) {
            'same_id'    => 'f.same_id AND f.same_type',
            'url_title'  => 'f.same_url AND f.same_type AND f.title_sim >= ' . $n('url_sim'),
            'title_org'  => 'f.same_type AND f.title_sim >= ' . $n('title_sim') . " AND f.same_org IS TRUE AND $overlap",
            'same_title' => "f.same_type AND f.same_title AND $overlap",
        };
    }

    /** Coppie che rispettano almeno una regola, con il nome della prima regola rispettata. */
    private function matchingPairs(): array
    {
        if (!$this->o['rules']) {
            return [];
        }
        $cases = [];
        foreach ($this->o['rules'] as $r) {
            $cases[] = 'WHEN ' . $this->ruleSql($r) . " THEN '$r'";
        }
        $sql = 'SELECT a_id, b_id, rule FROM (SELECT f.a_id, f.b_id, CASE ' . implode(' ', $cases) . ' END AS rule
                  FROM gwc.v_duplicate_features f) x WHERE rule IS NOT NULL';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
    }

    /**
     * Gruppi da unire secondo le regole.
     * @return array{groups: array<int, array{ids:int[], rules:string[]}>, skipped:int}
     */
    public function mergeGroups(): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $pairs = $this->matchingPairs();
        foreach ($pairs as [$a, $b]) {
            $a = (int) $a;
            $b = (int) $b;
            $parent[$a] ??= $a;
            $parent[$b] ??= $b;
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        }
        $groups = [];
        foreach ($pairs as [$a, $b, $rule]) {
            $g = $find((int) $a);
            $groups[$g]['ids'][(int) $a] = true;
            $groups[$g]['ids'][(int) $b] = true;
            $groups[$g]['rules'][$rule] = true;
        }
        $out = [];
        $skipped = 0;
        foreach ($groups as $g) {
            if (count($g['ids']) > $this->o['max_group']) {
                $skipped++;
                continue;
            }
            $ids = array_keys($g['ids']);
            sort($ids);
            $out[] = ['ids' => $ids, 'rules' => array_keys($g['rules'])];
        }
        usort($out, fn($x, $y) => count($y['ids']) <=> count($x['ids']));
        return ['groups' => $out, 'skipped' => $skipped];
    }

    /** Condizione SQL della regola di scarto. */
    private function rejectSql(): string
    {
        return "f.reason = 'similar_title' AND NOT f.same_id AND NOT f.same_url
                AND f.title_sim < :reject_sim
                AND f.same_org IS NOT TRUE
                AND f.bbox_overlap IS NOT NULL AND f.bbox_overlap < :reject_overlap";
    }

    private function bindReject(\PDOStatement $st): void
    {
        $st->bindValue(':reject_sim', (string) $this->o['reject_sim']);
        $st->bindValue(':reject_overlap', (string) $this->o['reject_overlap']);
    }

    /** Anteprima: numeri e alcuni esempi di cio' che le regole farebbero. */
    public function preview(int $examples = 15): array
    {
        $m = $this->mergeGroups();
        $resources = array_sum(array_map(fn($g) => count($g['ids']) - 1, $m['groups']));
        $byRule = [];
        foreach ($m['groups'] as $g) {
            foreach ($g['rules'] as $r) {
                $byRule[$r] = ($byRule[$r] ?? 0) + 1;
            }
        }
        $sample = array_slice($m['groups'], 0, $examples);
        $titles = $this->titles(array_merge(...array_map(fn($g) => $g['ids'], $sample ?: [['ids' => []]])));

        $st = $this->db->prepare("SELECT count(*) FROM gwc.v_duplicate_features f WHERE " . $this->rejectSql());
        $this->bindReject($st);
        $st->execute();
        $nReject = (int) $st->fetchColumn();
        $st = $this->db->prepare("SELECT f.a_id, f.b_id, round(f.title_sim::numeric, 2) AS sim, round(f.bbox_overlap::numeric, 2) AS overlap
                                    FROM gwc.v_duplicate_features f WHERE " . $this->rejectSql() . " ORDER BY f.title_sim DESC LIMIT $examples");
        $this->bindReject($st);
        $st->execute();
        $rejectSample = $st->fetchAll();
        $titles += $this->titles(array_merge(array_column($rejectSample, 'a_id'), array_column($rejectSample, 'b_id')));

        return [
            'groups'        => count($m['groups']),
            'resources'     => $resources,
            'skipped'       => $m['skipped'],
            'by_rule'       => $byRule,
            'sample'        => $sample,
            'reject'        => $nReject,
            'reject_sample' => $rejectSample,
            'titles'        => $titles,
        ];
    }

    /** Applica le regole di unione. @return array{0:int,1:int} gruppi, risorse unite */
    public function applyMerge(): array
    {
        $dg = new DuplicateGroups($this->db);
        $groups = 0;
        $merged = 0;
        foreach ($this->mergeGroups()['groups'] as $g) {
            $keep = $dg->bestOf($g['ids']);
            if ($keep > 0) {
                $merged += $dg->merge($keep, $g['ids'], 'unione automatica: ' . implode(', ', $g['rules']));
                $groups++;
            }
        }
        return [$groups, $merged];
    }

    /** Applica la regola di scarto. @return int coppie segnate come risorse diverse */
    public function applyReject(): int
    {
        $st = $this->db->prepare("UPDATE gwc.duplicate_candidates c
                                     SET status = 'rejected', decided_at = now(), note = 'scarto automatico'
                                    FROM gwc.v_duplicate_features f
                                   WHERE f.candidate_id = c.id AND " . $this->rejectSql());
        $this->bindReject($st);
        $st->execute();
        return $st->rowCount();
    }

    /** @return array<int,string> */
    private function titles(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        return $this->db->query('SELECT id, title FROM gwc.resources WHERE id IN (' . implode(',', $ids) . ')')
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
