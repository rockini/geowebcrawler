<?php
declare(strict_types=1);

namespace GeoWebCrawler\Web;

use GeoWebCrawler\Check\CheckRunner;
use GeoWebCrawler\Check\CheckSelection;
use GeoWebCrawler\Check\ServiceChecker;
use PDO;

/** Pagine e azioni della verifica dei servizi (?p=checks, check). */
final class CheckPages
{
    /** fino a questo numero di obiettivi la verifica si fa subito, dentro la pagina */
    private const INLINE_MAX = 30;
    private const PER_PAGE = 100;

    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $sel = new CheckSelection($this->db, $_GET);
        $counts = $sel->count();
        $pages = max(1, (int) ceil($counts['targets'] / self::PER_PAGE));
        $pageNo = min($pages, max(1, (int) ($_GET['n'] ?? 1)));
        $order = (string) ($_GET['order'] ?? 'priority');
        $rows = $sel->targets(self::PER_PAGE, ($pageNo - 1) * self::PER_PAGE, $order);
        // quadro generale per tipo
        $summary = $this->db->query(
            "SELECT x.kind, count(*) AS total,
                    count(*) FILTER (WHERE t.last_status = 'ok') AS ok,
                    count(*) FILTER (WHERE t.last_status = 'warn') AS warn,
                    count(*) FILTER (WHERE t.last_status = 'error') AS error,
                    count(*) FILTER (WHERE t.last_check_at IS NULL) AS never
               FROM (SELECT e.check_key, min(e.endpoint_type) AS kind
                       FROM gwc.endpoints e JOIN gwc.resources r ON r.id = e.resource_id AND r.status IN ('active','pending')
                      GROUP BY e.check_key) x
               LEFT JOIN gwc.check_targets t ON t.check_key = x.check_key
              GROUP BY ROLLUP (x.kind) ORDER BY x.kind NULLS FIRST")->fetchAll();
        $batches = $this->db->query("SELECT * FROM gwc.check_batches ORDER BY id DESC LIMIT 8")->fetchAll();
        $running = array_values(array_filter($batches, fn($b) => in_array($b['status'], ['queued', 'running', 'stopping'], true)));
        $types = $this->db->query("SELECT code, label FROM gwc.endpoint_types ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR);
        $sources = $this->db->query("SELECT id, name FROM gwc.sources ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
        $f = $sel->f;
        View::render('checks', compact('f', 'counts', 'rows', 'pages', 'pageNo', 'order', 'summary', 'batches', 'running', 'types', 'sources'),
                     'Verifica dei servizi');
    }

    public function target(string $key): void
    {
        $st = $this->db->prepare("SELECT * FROM gwc.check_targets WHERE check_key = ?");
        $st->execute([$key]);
        $t = $st->fetch() ?: null;
        $st = $this->db->prepare("SELECT e.*, r.title, r.status AS resource_status FROM gwc.endpoints e
                                    JOIN gwc.resources r ON r.id = e.resource_id WHERE e.check_key = ?
                                   ORDER BY r.status <> 'active', r.title LIMIT 500");
        $st->execute([$key]);
        $endpoints = $st->fetchAll();
        if (!$t && !$endpoints) {
            http_response_code(404);
            View::render('notfound', [], 'Non trovato');
            return;
        }
        // storico: una riga per verifica (le righe per punto di accesso dello stesso momento si riuniscono)
        $st = $this->db->prepare("SELECT date_trunc('second', checked_at) AS at, max(batch_id) AS batch_id,
                                         (array_agg(status ORDER BY status = 'ok'))[1] AS worst, min(http_status) AS http_status,
                                         max(response_ms) AS response_ms, count(*) AS n,
                                         count(*) FILTER (WHERE layer_found IS FALSE) AS missing,
                                         string_agg(DISTINCT note, '; ') AS notes
                                    FROM gwc.endpoint_checks WHERE check_key = ?
                                   GROUP BY 1 ORDER BY 1 DESC LIMIT 100");
        $st->execute([$key]);
        $history = $st->fetchAll();
        $kind = $t['kind'] ?? (in_array($endpoints[0]['endpoint_type'], ServiceChecker::OGC, true) ? $endpoints[0]['endpoint_type'] : 'link');
        $requestUrl = ServiceChecker::requestUrl($kind, $t['url'] ?? $endpoints[0]['url']);
        View::render('check', compact('key', 't', 'endpoints', 'history', 'kind', 'requestUrl'), 'Verifica');
    }

    public function action(string $action): ?string
    {
        switch ($action) {
            case 'check_start':
                set_time_limit(0);
                ignore_user_abort(true);
                $runner = new CheckRunner($this->db);
                $input = $_POST;
                if (($input['scope'] ?? '') === 'selected') {
                    $keys = array_values(array_filter(array_map('strval', (array) ($input['keys'] ?? []))));
                    if (!$keys) {
                        throw new \InvalidArgumentException('Spunta almeno un servizio o indirizzo');
                    }
                    $input = ['keys' => $keys];
                } else {
                    unset($input['keys']);
                }
                $limit = max(1, min(100000, (int) ($input['limit'] ?? 1000)));
                $id = $runner->createBatch(array_intersect_key($input, array_flip(CheckSelection::FILTERS)), $limit, 'web');
                return $this->launch($runner, $id);

            case 'check_resume':
                $runner = new CheckRunner($this->db);
                return $this->launch($runner, (int) $_POST['id']);

            case 'check_stop':
                $this->db->prepare("UPDATE gwc.check_batches SET status = 'stopping' WHERE id = ? AND status = 'running'")
                         ->execute([(int) $_POST['id']]);
                // un lotto mai partito (es. PHP da riga di comando non avviato) si chiude subito
                $this->db->prepare("UPDATE gwc.check_batches SET status = 'stopped', finished_at = now(), message = 'Fermata'
                                     WHERE id = ? AND status = 'queued'")->execute([(int) $_POST['id']]);
                Session::flash('Richiesta di arresto inviata: le verifiche in corso finiscono e poi si ferma.');
                return $this->back(View::url('checks'));
        }
        return null;
    }

    /** Piccole verifiche subito; le altre in background, oppure (se non si puo') nella pagina per al massimo 4 minuti. */
    private function launch(CheckRunner $runner, int $id): string
    {
        $b = $runner->batch($id) ?? throw new \InvalidArgumentException('Verifica inesistente');
        $todo = (int) $b['n_targets'] - (int) $b['n_done'];
        if ($todo <= 0 && $b['status'] === 'queued') {
            $this->db->prepare("UPDATE gwc.check_batches SET status = 'done', finished_at = now(), message = 'Niente da verificare' WHERE id = ?")
                     ->execute([$id]);
            Session::flash('Nessun servizio o indirizzo corrisponde alla scelta.', 'warn');
            return $this->back(View::url('checks'));
        }
        if ($todo > self::INLINE_MAX && CheckRunner::launchBackground($id)) {
            Session::flash("Verifica avviata in background ($todo servizi o indirizzi): la pagina si aggiorna da sola.");
            return View::url('checks');
        }
        $r = $runner->run($id, $todo > self::INLINE_MAX ? 240 : 90);
        Session::flash("Verificati {$r['n_done']}: funzionano {$r['n_ok']}, avvisi {$r['n_warn']}, non funzionano {$r['n_error']}"
                       . ($r['message'] ? '. ' . $r['message'] : '.'), $r['n_error'] ? 'warn' : 'ok');
        return $this->back(View::url('checks'));
    }

    private function back(string $default): string
    {
        $b = (string) ($_POST['back'] ?? '');
        return str_starts_with($b, '?') ? $b : $default;
    }
}
