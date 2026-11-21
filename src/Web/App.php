<?php
declare(strict_types=1);

namespace GeoWebCrawler\Web;

use GeoWebCrawler\Harvest\HarvestRunner;
use GeoWebCrawler\Repository\DuplicateGroups;
use GeoWebCrawler\Repository\DuplicateRules;
use GeoWebCrawler\Repository\Organizations;
use GeoWebCrawler\Repository\SourceDiscovery;
use GeoWebCrawler\Repository\SourceRepository;
use PDO;

/**
 * Interfaccia web: un "controller" per pagina, scelta con il parametro ?p=.
 * Le azioni che modificano i dati arrivano in POST con il campo "action" e un token CSRF.
 */
final class App
{
    private const PER_PAGE = 50;

    public function __construct(private PDO $db) {}

    public function handle(): void
    {
        Session::start();
        $page = $_GET['p'] ?? 'home';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sparql_run') {
            Session::checkCsrf();
            (new SparqlPages($this->db))->runFromEditor();   // il risultato si mostra subito
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Session::checkCsrf();
            try {
                $redirect = $this->action((string) ($_POST['action'] ?? ''));
            } catch (\Throwable $e) {
                Session::flash('Errore: ' . $e->getMessage(), 'err');
                $redirect = $_SERVER['HTTP_REFERER'] ?? View::url($page);
            }
            header('Location: ' . $redirect, true, 303);
            return;
        }

        match ($page) {
            'sources'    => $this->sources(),
            'source'     => $this->source((int) ($_GET['id'] ?? 0)),
            'run'        => $this->run((int) ($_GET['id'] ?? 0)),
            'resources'  => $this->resources(),
            'resource'   => $this->resource((int) ($_GET['id'] ?? 0)),
            'duplicates' => $this->duplicates(),
            'dup_rules'  => $this->duplicateRules(),
            'orgs'       => $this->organizations(),
            'proposals'  => $this->proposals(),
            'sparql'       => (new SparqlPages($this->db))->index(),
            'sparql_group' => (new SparqlPages($this->db))->group((int) ($_GET['id'] ?? 0)),
            'sparql_query' => (new SparqlPages($this->db))->query(),
            'files'        => (new SparqlPages($this->db))->files(),
            'checks'       => (new CheckPages($this->db))->index(),
            'check'        => (new CheckPages($this->db))->target((string) ($_GET['key'] ?? '')),
            'file'         => (new SparqlPages($this->db))->file((int) ($_GET['id'] ?? 0)),
            default      => $this->home(),
        };
    }

    // ------------------------------------------------------------------ pagine

    private function home(): void
    {
        $counts = $this->db->query("SELECT
            (SELECT count(*) FROM gwc.sources) AS sources,
            (SELECT count(*) FROM gwc.resources WHERE status IN ('active','pending')) AS resources,
            (SELECT count(*) FROM gwc.endpoints e JOIN gwc.resources r ON r.id = e.resource_id
              WHERE r.status IN ('active','pending')) AS endpoints,
            (SELECT count(*) FROM gwc.v_duplicates_pending) AS duplicates,
            (SELECT count(*) FROM gwc.resources WHERE status = 'removed') AS removed,
            (SELECT count(*) FROM gwc.resource_links) AS links,
            (SELECT count(*) FROM gwc.sources WHERE discovery_status = 'proposed') AS proposed")->fetch();
        $themes = $this->db->query("SELECT t.code, t.label, count(r.id) AS n FROM gwc.themes t
                                     JOIN gwc.resources r ON r.themes @> ARRAY[t.code] AND r.status IN ('active','pending')
                                    GROUP BY 1, 2 ORDER BY 3 DESC")->fetchAll();
        $runs = $this->db->query("SELECT h.*, s.name FROM gwc.harvest_runs h JOIN gwc.sources s ON s.id = h.source_id
                                   ORDER BY h.started_at DESC LIMIT 10")->fetchAll();
        $byType = $this->db->query("SELECT e.endpoint_type, count(*) n FROM gwc.endpoints e
                                     JOIN gwc.resources r ON r.id = e.resource_id AND r.status IN ('active','pending')
                                    GROUP BY 1 ORDER BY 2 DESC")->fetchAll();
        View::render('home', compact('counts', 'runs', 'byType', 'themes'));
    }

    private function sources(): void
    {
        $sources = (new SourceRepository($this->db))->all();
        $types = $this->db->query("SELECT code, label, phase FROM gwc.source_types ORDER BY phase, label")->fetchAll();
        $proposed = (int) $this->db->query("SELECT count(*) FROM gwc.sources WHERE discovery_status = 'proposed'")->fetchColumn();
        View::render('sources', compact('sources', 'types', 'proposed'), 'Sorgenti');
    }

    private function source(int $id): void
    {
        $st = $this->db->prepare("SELECT * FROM gwc.sources WHERE id = ?");
        $st->execute([$id]);
        $source = $st->fetch() ?: $this->notFound();
        $types = $this->db->query("SELECT code, label FROM gwc.source_types ORDER BY phase, label")->fetchAll();
        $st = $this->db->prepare("SELECT * FROM gwc.harvest_runs WHERE source_id = ? ORDER BY started_at DESC LIMIT 30");
        $st->execute([$id]);
        $runs = $st->fetchAll();
        View::render('source', compact('source', 'types', 'runs'), $source['name']);
    }

    private function run(int $id): void
    {
        $st = $this->db->prepare("SELECT h.*, s.name FROM gwc.harvest_runs h JOIN gwc.sources s ON s.id = h.source_id WHERE h.id = ?");
        $st->execute([$id]);
        $run = $st->fetch() ?: $this->notFound();
        View::render('run', compact('run'), 'Raccolta #' . $id);
    }

    private function resources(): void
    {
        $search = new ResourceSearch($this->db, $_GET);
        $total = $search->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $pageNo = min($pages, max(1, (int) ($_GET['n'] ?? 1)));
        $rows = $search->rows(self::PER_PAGE, ($pageNo - 1) * self::PER_PAGE);
        $facets = $search->facets();
        View::render('resources', compact('search', 'rows', 'total', 'facets', 'pageNo', 'pages'), 'Risorse');
    }

    private function resource(int $id): void
    {
        $st = $this->db->prepare("SELECT r.*, ST_AsGeoJSON(r.bbox) AS bbox_json,
                                         ST_XMin(r.bbox) w, ST_YMin(r.bbox) s, ST_XMax(r.bbox) e, ST_YMax(r.bbox) n,
                                         l.code AS license_code, l.label AS license_label, l.url AS license_url
                                    FROM gwc.resources r LEFT JOIN gwc.licenses l ON l.id = r.license_id WHERE r.id = ?");
        $st->execute([$id]);
        $r = $st->fetch() ?: $this->notFound();

        $q = fn(string $sql) => (function () use ($sql, $id) { $s = $this->db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); })();
        $endpoints = $q("SELECT e.*, f.label AS format_label, t.label AS type_label FROM gwc.endpoints e
                           JOIN gwc.endpoint_types t ON t.code = e.endpoint_type
                           LEFT JOIN gwc.formats f ON f.id = e.format_id WHERE e.resource_id = ? ORDER BY e.endpoint_type, e.id");
        $parties = $q("SELECT o.name, o.email, p.role FROM gwc.resource_parties p JOIN gwc.organizations o ON o.id = p.organization_id
                        WHERE p.resource_id = ? ORDER BY p.role, o.name");
        $origins = $q("SELECT s.id, s.name, s.source_type, rs.record_ref, rs.first_seen_at, rs.last_seen_at, rs.removed_at
                         FROM gwc.resource_sources rs JOIN gwc.sources s ON s.id = rs.source_id WHERE rs.resource_id = ?");
        $merged = $q("SELECT id, title FROM gwc.resources WHERE canonical_id = ? ORDER BY id");
        $candidates = $q("SELECT c.*, CASE WHEN c.resource_a = r.id THEN c.resource_b ELSE c.resource_a END AS other_id,
                                 o.title AS other_title
                            FROM gwc.duplicate_candidates c
                            JOIN gwc.resources r ON r.id IN (c.resource_a, c.resource_b)
                            JOIN gwc.resources o ON o.id = CASE WHEN c.resource_a = r.id THEN c.resource_b ELSE c.resource_a END
                           WHERE r.id = ? AND c.status = 'pending'");
        $services = $q("SELECT l.origin, s.id, s.title, s.status FROM gwc.resource_links l JOIN gwc.resources s ON s.id = l.service_id
                         WHERE l.dataset_id = ? ORDER BY s.title");
        $datasets = $q("SELECT l.origin, d.id, d.title, d.status FROM gwc.resource_links l JOIN gwc.resources d ON d.id = l.dataset_id
                         WHERE l.service_id = ? ORDER BY d.title");
        $themes = $q("SELECT t.code, t.label FROM gwc.resources r JOIN gwc.themes t ON t.code = ANY (r.themes) WHERE r.id = ? ORDER BY t.label");
        View::render('resource', compact('r', 'endpoints', 'parties', 'origins', 'merged', 'candidates', 'services', 'datasets', 'themes'), $r['title']);
    }

    private function duplicates(): void
    {
        $all = (new DuplicateGroups($this->db))->all();
        $total = (int) $this->db->query("SELECT count(*) FROM gwc.v_duplicates_pending")->fetchColumn();
        $byReason = $this->db->query("SELECT reason, count(*) FROM gwc.v_duplicates_pending GROUP BY reason ORDER BY 2 DESC")
                             ->fetchAll(\PDO::FETCH_KEY_PAIR);
        $certain = count(array_filter($all, fn($g) => $g['certain']));
        $nAll = count($all);

        // filtri sull'elenco dei gruppi
        $filter = [
            'reason' => (string) ($_GET['reason'] ?? ''),
            'size'   => max(0, (int) ($_GET['size'] ?? 0)),
            'q'      => trim((string) ($_GET['q'] ?? '')),
        ];
        if ($filter['reason'] !== '') {
            $all = array_values(array_filter($all, fn($g) => in_array($filter['reason'], array_column($g['pairs'], 'reason'), true)));
        }
        if ($filter['size'] > 0) {
            $all = array_values(array_filter($all, fn($g) => count($g['ids']) >= $filter['size']));
        }
        if ($filter['q'] !== '') {
            $needle = mb_strtolower($filter['q']);
            $all = array_values(array_filter($all, function ($g) use ($needle) {
                foreach ($g['pairs'] as $p) {
                    if (str_contains(mb_strtolower($p['a_title'] . ' ' . $p['b_title']), $needle)) {
                        return true;
                    }
                }
                return false;
            }));
        }
        $perPage = 20;
        $pages = max(1, (int) ceil(count($all) / $perPage));
        $pageNo = min($pages, max(1, (int) ($_GET['n'] ?? 1)));
        $groups = array_slice($all, ($pageNo - 1) * $perPage, $perPage);
        $nGroups = count($all);
        unset($all);

        // dettagli delle risorse dei gruppi mostrati (al massimo 60 per gruppo)
        $ids = [];
        $cands = [];
        foreach ($groups as $g) {
            foreach (array_slice($g['ids'], 0, 60) as $id) {
                $ids[$id] = true;
            }
            foreach ($g['pairs'] as $p) {
                $cands[] = (int) $p['candidate_id'];
            }
        }
        $details = [];
        if ($ids) {
            $in = implode(',', array_map('intval', array_keys($ids)));
            foreach ($this->db->query("SELECT r.id, r.resource_type, r.title, rr.external_id, r.date_published, r.date_revised,
                                              rr.metadata_date, r.license, r.organizations, r.formats, r.endpoint_types, r.n_endpoints,
                                              (SELECT string_agg(s.name, '; ') FROM gwc.resource_sources rs
                                                 JOIN gwc.sources s ON s.id = rs.source_id WHERE rs.resource_id = r.id) AS source_names,
                                              CASE WHEN r.bbox IS NULL THEN NULL ELSE
                                                   concat_ws(', ', round(ST_XMin(r.bbox)::numeric, 2), round(ST_YMin(r.bbox)::numeric, 2),
                                                             round(ST_XMax(r.bbox)::numeric, 2), round(ST_YMax(r.bbox)::numeric, 2)) END AS bbox_txt,
                                              ST_AsGeoJSON(rr.bbox, 4) AS bbox_json
                                         FROM gwc.v_resources r JOIN gwc.resources rr ON rr.id = r.id WHERE r.id IN ($in)") as $d) {
                $details[$d['id']] = $d;
            }
        }
        // caratteristiche delle coppie mostrate (per il grafo)
        $features = [];
        if ($cands) {
            foreach ($this->db->query("SELECT candidate_id, title_sim, same_org, bbox_overlap, same_url, same_source
                                         FROM gwc.v_duplicate_features WHERE candidate_id IN (" . implode(',', $cands) . ")") as $f) {
                $features[$f['candidate_id']] = $f;
            }
        }
        View::render('duplicates', compact('groups', 'total', 'nGroups', 'nAll', 'certain', 'byReason', 'details', 'features',
                                           'filter', 'pageNo', 'pages'), 'Duplicati');
    }

    private function duplicateRules(): void
    {
        $rules = new DuplicateRules($this->db, $_GET);
        $preview = isset($_GET['preview']) ? $rules->preview() : null;
        $o = $rules->options();
        View::render('dup_rules', compact('o', 'preview'), 'Unione automatica dei duplicati');
    }

    private function organizations(): void
    {
        $orgs = new Organizations($this->db);
        $q = trim((string) ($_GET['q'] ?? ''));
        $total = $orgs->count($q);
        $pages = max(1, (int) ceil($total / 100));
        $pageNo = min($pages, max(1, (int) ($_GET['n'] ?? 1)));
        $list = $orgs->list($q, 100, ($pageNo - 1) * 100);
        $suggestions = $q === '' && $pageNo === 1 ? $orgs->suggestions(30) : [];
        $nSuggestions = $q === '' ? count($orgs->suggestions(100000)) : 0;
        View::render('orgs', compact('q', 'list', 'total', 'pages', 'pageNo', 'suggestions', 'nSuggestions'), 'Enti');
    }

    private function proposals(): void
    {
        $rows = $this->db->query("SELECT * FROM gwc.sources WHERE discovery_status = 'proposed'
                                   ORDER BY discovered_count DESC NULLS LAST, name LIMIT 500")->fetchAll();
        $counts = $this->db->query("SELECT discovery_status, count(*) FROM gwc.sources
                                     WHERE discovery_status IS NOT NULL GROUP BY 1")->fetchAll(\PDO::FETCH_KEY_PAIR);
        View::render('proposals', compact('rows', 'counts'), 'Sorgenti proposte');
    }

    // ------------------------------------------------------------------ azioni

    private function action(string $action): string
    {
        $id = (int) ($_POST['id'] ?? 0);
        switch ($action) {
            case 'source_save':
                $params = trim((string) ($_POST['params'] ?? '')) ?: '{}';
                $decoded = json_decode($params, true);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException('I parametri devono essere un oggetto JSON valido, es. {"max_records": 200}');
                }
                $name = trim((string) $_POST['name']);
                $url = trim((string) $_POST['url']);
                if ($name === '' || !preg_match('~^https?://~i', $url)) {
                    throw new \InvalidArgumentException('Servono un nome e un URL che inizi con http:// o https://');
                }
                if ($id > 0) {
                    $this->db->prepare("UPDATE gwc.sources SET name = ?, source_type = ?, url = ?, description = ?,
                                               params = CAST(? AS jsonb), enabled = ?, harvest_every = CAST(? AS interval)
                                         WHERE id = ?")
                        ->execute([$name, $_POST['source_type'], $url, trim((string) ($_POST['description'] ?? '')) ?: null,
                                   json_encode((object) $decoded), isset($_POST['enabled']) ? 'true' : 'false',
                                   ((int) ($_POST['harvest_days'] ?? 7)) . ' days', $id]);
                    Session::flash('Sorgente aggiornata.');
                    return View::url('source', ['id' => $id]);
                }
                [$newId, $isNew] = (new SourceRepository($this->db))->upsert(
                    $name, (string) $_POST['source_type'], $url, $decoded, true, 'ui',
                    trim((string) ($_POST['description'] ?? '')) ?: null);
                Session::flash($isNew ? 'Sorgente aggiunta.' : 'Esisteva gia\' una sorgente con questo URL: l\'ho aggiornata.', $isNew ? 'ok' : 'warn');
                return View::url('source', ['id' => $newId]);

            case 'source_toggle':
                $this->db->prepare("UPDATE gwc.sources SET enabled = NOT enabled WHERE id = ?")->execute([$id]);
                return View::url('sources');

            case 'source_delete':
                $this->db->prepare("DELETE FROM gwc.sources WHERE id = ?")->execute([$id]);
                Session::flash('Sorgente eliminata. Le risorse raccolte restano in archivio.');
                return View::url('sources');

            case 'harvest':
                set_time_limit(0);
                ignore_user_abort(true);
                $run = (new HarvestRunner($this->db))->run($id, null, !empty($_POST['full']));
                Session::flash($run['message'], $run['status'] === 'ok' ? 'ok' : ($run['status'] === 'partial' ? 'warn' : 'err'));
                return View::url('run', ['id' => $run['id']]);

            case 'merge':
                $keep = (int) $_POST['keep'];
                $drop = (int) $_POST['drop'];
                $this->db->prepare("SELECT gwc.merge_resources(?, ?, ?)")
                    ->execute([$keep, $drop, trim((string) ($_POST['note'] ?? '')) ?: null]);
                Session::flash("Risorsa #$drop unita a #$keep.");
                return $this->back(View::url('duplicates'));

            case 'not_duplicate':
                $this->db->prepare("SELECT gwc.reject_duplicate(?)")->execute([$id]);
                Session::flash('Segnate come risorse diverse: la coppia non verra\' piu\' proposta.');
                return $this->back(View::url('duplicates'));

            case 'group_merge':
                $keep = (int) ($_POST['keep'] ?? 0);
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                if ($keep <= 0 || !in_array($keep, $ids, true)) {
                    throw new \InvalidArgumentException('Scegli la risorsa da tenere');
                }
                $n = (new DuplicateGroups($this->db))->merge($keep, $ids, trim((string) ($_POST['note'] ?? '')) ?: null);
                Session::flash("$n risorse unite a #$keep.");
                return $this->back(View::url('duplicates'));

            case 'group_reject':
                $n = (new DuplicateGroups($this->db))->reject(array_map('intval', (array) ($_POST['ids'] ?? [])));
                Session::flash("Segnate come risorse diverse ($n coppie): non verranno piu' proposte.");
                return $this->back(View::url('duplicates'));

            case 'auto_merge':
                set_time_limit(0);
                [$g, $n] = (new DuplicateGroups($this->db))->autoMerge();
                Session::flash("Unione automatica: $g gruppi con lo stesso identificativo, $n risorse unite.");
                return View::url('duplicates');

            case 'dup_rules_merge':
                set_time_limit(0);
                $opts = json_decode((string) ($_POST['options'] ?? '{}'), true) ?: [];
                [$g, $n] = (new DuplicateRules($this->db, $opts))->applyMerge();
                Session::flash("Unione automatica: $g gruppi, $n risorse unite. Si possono annullare dalla scheda di ogni risorsa.");
                return View::url('duplicates');

            case 'dup_rules_reject':
                set_time_limit(0);
                $opts = json_decode((string) ($_POST['options'] ?? '{}'), true) ?: [];
                $n = (new DuplicateRules($this->db, $opts))->applyReject();
                Session::flash("$n coppie segnate come risorse diverse: non verranno piu' proposte.");
                return View::url('duplicates');

            case 'org_merge':
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $main = (int) ($_POST['main'] ?? 0);
                if ($main <= 0 || count($ids) < 2) {
                    throw new \InvalidArgumentException("Seleziona almeno due enti e scegli quello principale");
                }
                $n = (new Organizations($this->db))->merge($main, $ids);
                Session::flash("$n enti riuniti sotto l'ente principale.");
                return $this->back(View::url('orgs'));

            case 'org_unmerge':
                (new Organizations($this->db))->unmerge($id);
                Session::flash("L'ente e' di nuovo indipendente.");
                return $this->back(View::url('orgs'));

            case 'org_auto':
                $n = (new Organizations($this->db))->autoMerge();
                Session::flash("Applicati i suggerimenti: $n enti riuniti.");
                return View::url('orgs');

            case 'discover':
                $n = (new SourceDiscovery($this->db))->discover();
                Session::flash("Ricerca completata: $n nuove sorgenti proposte.");
                return View::url('proposals');

            case 'proposals':
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                if (!$ids) {
                    throw new \InvalidArgumentException('Seleziona almeno una sorgente');
                }
                $accept = ($_POST['decision'] ?? '') === 'accept';
                $st = $this->db->prepare("UPDATE gwc.sources SET discovery_status = ?, enabled = ?
                                           WHERE discovery_status IN ('proposed','ignored') AND id = ANY (CAST(? AS bigint[]))");
                $st->execute([$accept ? 'accepted' : 'ignored', $accept ? 'true' : 'false', '{' . implode(',', $ids) . '}']);
                Session::flash($accept ? $st->rowCount() . " sorgenti aggiunte e attivate: verranno raccolte con le altre."
                                       : $st->rowCount() . " proposte ignorate: non verranno piu' proposte.");
                return View::url('proposals');

            case 'detect_all':
                $n = (int) $this->db->query("SELECT gwc.detect_duplicates()")->fetchColumn();
                Session::flash("Controllo completato: $n nuove coppie da verificare.");
                return View::url('duplicates');

            case 'resource_status':
                $status = (string) $_POST['status'];
                if (!in_array($status, ['active', 'pending', 'rejected'], true)) {
                    throw new \InvalidArgumentException('Stato non valido');
                }
                $this->db->prepare("UPDATE gwc.resources SET status = ?, notes = COALESCE(NULLIF(?, ''), notes)
                                     WHERE id = ? AND status <> 'duplicate'")
                    ->execute([$status, trim((string) ($_POST['note'] ?? '')), $id]);
                Session::flash('Stato aggiornato.');
                return View::url('resource', ['id' => $id]);

            case 'unmerge':
                // annulla un'unione: la risorsa torna attiva (i link spostati restano sulla risorsa tenuta)
                $this->db->prepare("UPDATE gwc.resources SET status = 'active', canonical_id = NULL WHERE id = ? AND status = 'duplicate'")
                    ->execute([$id]);
                Session::flash("Risorsa #$id ripristinata come risorsa indipendente.");
                return View::url('resource', ['id' => $id]);
        }
        $r = (new CheckPages($this->db))->action($action) ?? (new SparqlPages($this->db))->action($action);
        if ($r !== null) {
            return $r;
        }
        throw new \InvalidArgumentException("Azione sconosciuta: $action");
    }

    /** Pagina di ritorno indicata dal form, solo se interna. */
    private function back(string $default): string
    {
        $b = (string) ($_POST['back'] ?? '');
        return str_starts_with($b, '?') ? $b : $default;
    }

    private function notFound(): never
    {
        http_response_code(404);
        View::render('notfound', [], 'Non trovato');
        exit;
    }
}
