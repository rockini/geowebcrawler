<?php
declare(strict_types=1);

namespace GeoWebCrawler\Web;

use GeoWebCrawler\Harvest\HarvestRunner;
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
            (SELECT count(*) FROM gwc.v_duplicates_pending) AS duplicates")->fetch();
        $runs = $this->db->query("SELECT h.*, s.name FROM gwc.harvest_runs h JOIN gwc.sources s ON s.id = h.source_id
                                   ORDER BY h.started_at DESC LIMIT 10")->fetchAll();
        $byType = $this->db->query("SELECT e.endpoint_type, count(*) n FROM gwc.endpoints e
                                     JOIN gwc.resources r ON r.id = e.resource_id AND r.status IN ('active','pending')
                                    GROUP BY 1 ORDER BY 2 DESC")->fetchAll();
        View::render('home', compact('counts', 'runs', 'byType'));
    }

    private function sources(): void
    {
        $sources = (new SourceRepository($this->db))->all();
        $types = $this->db->query("SELECT code, label, phase FROM gwc.source_types ORDER BY phase, label")->fetchAll();
        View::render('sources', compact('sources', 'types'), 'Sorgenti');
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
        $f = [
            'q'      => trim((string) ($_GET['q'] ?? '')),
            'type'   => (string) ($_GET['type'] ?? ''),
            'status' => (string) ($_GET['status'] ?? 'active'),
            'source' => (int) ($_GET['source'] ?? 0),
            'ep'     => (string) ($_GET['ep'] ?? ''),
            'license'=> (string) ($_GET['license'] ?? ''),
        ];
        $where = [];
        $args = [];
        if ($f['q'] !== '') {
            $where[] = "(r1.search_vector @@ plainto_tsquery('simple', :q) OR r0.title ILIKE :qlike)";
            $args[':q'] = $f['q'];
            $args[':qlike'] = '%' . $f['q'] . '%';
        }
        if ($f['type'] !== '') {
            $where[] = 'r0.resource_type = :type';
            $args[':type'] = $f['type'];
        }
        if ($f['status'] !== 'all') {
            $where[] = $f['status'] === 'active' ? "r0.status IN ('active','pending')" : 'r0.status = :status';
            if ($f['status'] !== 'active') {
                $args[':status'] = $f['status'];
            }
        }
        if ($f['source'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM gwc.resource_sources rs WHERE rs.resource_id = r0.id AND rs.source_id = :source)';
            $args[':source'] = $f['source'];
        }
        if ($f['ep'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM gwc.endpoints e WHERE e.resource_id = r0.id AND e.endpoint_type = :ep)';
            $args[':ep'] = $f['ep'];
        }
        if ($f['license'] !== '') {
            $where[] = $f['license'] === '-' ? 'r0.license_code IS NULL' : 'r0.license_code = :license';
            if ($f['license'] !== '-') {
                $args[':license'] = $f['license'];
            }
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        // v_resources non ha search_vector: si unisce alla tabella
        $from = "FROM gwc.v_resources r0 JOIN gwc.resources r1 ON r1.id = r0.id";

        $st = $this->db->prepare("SELECT count(*) $from $sqlWhere");
        $st->execute($args);
        $total = (int) $st->fetchColumn();

        $pageNo = max(1, (int) ($_GET['n'] ?? 1));
        $st = $this->db->prepare("SELECT r0.* $from $sqlWhere ORDER BY r0.last_seen_at DESC, r0.id DESC
                                  LIMIT " . self::PER_PAGE . " OFFSET " . (($pageNo - 1) * self::PER_PAGE));
        $st->execute($args);
        $rows = $st->fetchAll();

        $sources = $this->db->query("SELECT id, name FROM gwc.sources ORDER BY name")->fetchAll();
        $epTypes = $this->db->query("SELECT code, label FROM gwc.endpoint_types ORDER BY label")->fetchAll();
        $licenses = $this->db->query("SELECT code, label FROM gwc.licenses ORDER BY label")->fetchAll();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        View::render('resources', compact('rows', 'total', 'f', 'sources', 'epTypes', 'licenses', 'pageNo', 'pages'), 'Risorse');
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
        $origins = $q("SELECT s.id, s.name, s.source_type, rs.record_ref, rs.first_seen_at, rs.last_seen_at
                         FROM gwc.resource_sources rs JOIN gwc.sources s ON s.id = rs.source_id WHERE rs.resource_id = ?");
        $merged = $q("SELECT id, title FROM gwc.resources WHERE canonical_id = ? ORDER BY id");
        $candidates = $q("SELECT c.*, CASE WHEN c.resource_a = r.id THEN c.resource_b ELSE c.resource_a END AS other_id,
                                 o.title AS other_title
                            FROM gwc.duplicate_candidates c
                            JOIN gwc.resources r ON r.id IN (c.resource_a, c.resource_b)
                            JOIN gwc.resources o ON o.id = CASE WHEN c.resource_a = r.id THEN c.resource_b ELSE c.resource_a END
                           WHERE r.id = ? AND c.status = 'pending'");
        View::render('resource', compact('r', 'endpoints', 'parties', 'origins', 'merged', 'candidates'), $r['title']);
    }

    private function duplicates(): void
    {
        $pairs = $this->db->query("SELECT * FROM gwc.v_duplicates_pending LIMIT 100")->fetchAll();
        $total = (int) $this->db->query("SELECT count(*) FROM gwc.v_duplicates_pending")->fetchColumn();
        // dettagli delle risorse coinvolte, per il confronto affiancato
        $ids = [];
        foreach ($pairs as $p) {
            $ids[$p['a_id']] = $ids[$p['b_id']] = true;
        }
        $details = [];
        if ($ids) {
            $in = implode(',', array_map('intval', array_keys($ids)));
            foreach ($this->db->query("SELECT r.id, r.resource_type, r.title, rr.external_id, r.date_published, r.date_revised,
                                              r.license, r.organizations, r.formats, r.endpoint_types, r.n_endpoints,
                                              (SELECT string_agg(s.name, '; ') FROM gwc.resource_sources rs
                                                 JOIN gwc.sources s ON s.id = rs.source_id WHERE rs.resource_id = r.id) AS source_names,
                                              CASE WHEN r.bbox IS NULL THEN NULL ELSE
                                                   concat_ws(', ', round(ST_XMin(r.bbox)::numeric, 2), round(ST_YMin(r.bbox)::numeric, 2),
                                                             round(ST_XMax(r.bbox)::numeric, 2), round(ST_YMax(r.bbox)::numeric, 2)) END AS bbox_txt
                                         FROM gwc.v_resources r JOIN gwc.resources rr ON rr.id = r.id WHERE r.id IN ($in)") as $d) {
                $details[$d['id']] = $d;
            }
        }
        View::render('duplicates', compact('pairs', 'total', 'details'), 'Duplicati');
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
                $run = (new HarvestRunner($this->db))->run($id);
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
