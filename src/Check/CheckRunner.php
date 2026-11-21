<?php
declare(strict_types=1);

namespace GeoWebCrawler\Check;

use PDO;

/**
 * Esegue un lotto di verifiche (gwc.check_batches) e ne salva gli esiti:
 *  - gwc.check_targets: ultimo esito di ogni servizio o indirizzo;
 *  - gwc.endpoint_checks: storico per punto di accesso (con "layer presente" per i servizi OGC);
 *  - gwc.endpoints.last_check_*: ultimo esito, usato nella ricerca e nelle schede delle risorse.
 */
final class CheckRunner
{
    private const CHUNK = 200;

    /** @var (callable(string):void)|null */
    private $echo = null;
    private float $lastStopCheck = 0;

    public function __construct(private PDO $db) {}

    public function onProgress(?callable $echo): self
    {
        $this->echo = $echo;
        return $this;
    }

    private function say(string $msg): void
    {
        if ($this->echo) {
            ($this->echo)(date('H:i:s') . ' ' . $msg);
        }
    }

    /** Crea un lotto con i filtri dati. @return int id del lotto */
    public function createBatch(array $filter, int $limit, string $origin = 'web'): int
    {
        $sel = new CheckSelection($this->db, $filter);
        $n = min($limit, $sel->count()['targets']);
        $st = $this->db->prepare("INSERT INTO gwc.check_batches (origin, filter, description, n_targets)
                                  VALUES (?, CAST(? AS jsonb), ?, ?) RETURNING id");
        $st->execute([$origin, json_encode($sel->params() + ['limit' => $limit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                      $sel->describe(), $n]);
        return (int) $st->fetchColumn();
    }

    public function batch(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM gwc.check_batches WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Esegue un lotto. $maxSeconds: dopo questo tempo non si avviano nuove verifiche (per l'esecuzione
     * dentro una pagina web); quelle mancanti restano da fare e il lotto risulta "fermato".
     */
    public function run(int $batchId, ?int $maxSeconds = null, array $checkerOptions = []): array
    {
        $b = $this->batch($batchId) ?? throw new \InvalidArgumentException("Verifica $batchId inesistente");
        if (!in_array($b['status'], ['queued', 'stopped', 'error'], true)) {
            throw new \RuntimeException("La verifica $batchId e' gia' in corso o finita");
        }
        $filter = json_decode((string) $b['filter'], true) ?: [];
        $limit = max(1, (int) ($filter['limit'] ?? 1000));
        $sel = new CheckSelection($this->db, $filter);
        // ripresa di un lotto fermato: solo gli obiettivi non ancora verificati in questo lotto
        $targets = array_values(array_filter($sel->targets($limit),
            fn($t) => $b['status'] === 'queued' || (int) ($this->lastBatchOf($t['check_key']) ?? 0) !== $batchId));
        $this->db->prepare("UPDATE gwc.check_batches SET status = 'running', started_at = COALESCE(started_at, now()),
                                   n_targets = n_done + ?, message = NULL WHERE id = ?")
                 ->execute([count($targets), $batchId]);
        $this->say(count($targets) . ' servizi o indirizzi da verificare');

        $start = microtime(true);
        $checker = new ServiceChecker($checkerOptions);
        $timeUp = fn() => $maxSeconds !== null && microtime(true) - $start > $maxSeconds;
        $stop = function () use ($batchId, $timeUp): bool {
            if ($timeUp()) {
                return true;
            }
            if (microtime(true) - $this->lastStopCheck < 2) {
                return false;
            }
            $this->lastStopCheck = microtime(true);
            return $this->db->query("SELECT status FROM gwc.check_batches WHERE id = " . (int) $batchId)->fetchColumn() === 'stopping';
        };
        $done = 0;
        $error = null;
        try {
            foreach (array_chunk($targets, self::CHUNK) as $chunk) {
                if ($stop()) {
                    break;
                }
                $endpoints = CheckSelection::endpointsOf($this->db, array_column($chunk, 'check_key'));
                $jobs = array_map(fn($t) => ['key' => $t['check_key'], 'kind' => $this->kind($t), 'url' => $t['url']], $chunk);
                $checker->run($jobs, function (string $key, array $res) use ($batchId, $endpoints, &$done) {
                    $this->save($batchId, $key, $res, $endpoints[$key] ?? []);
                    $done++;
                    $this->say(sprintf('%5d %-5s %-7s %5d ms  %s%s', $done, strtoupper($res['kind']), $res['status'], $res['ms'],
                                       $res['url'], $res['note'] ? '  - ' . $res['note'] : ''));
                }, $stop);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->say('ERRORE: ' . $error);
        }
        $b = $this->batch($batchId);
        $remaining = (int) $b['n_targets'] - (int) $b['n_done'];
        $status = $error !== null ? 'error' : ($remaining > 0 ? 'stopped' : 'done');
        $msg = $error ?? ($remaining > 0
            ? ($timeUp() ? "Tempo massimo raggiunto: ne restano $remaining (premi \"Continua\")" : "Fermata: ne restano $remaining")
            : null);
        $this->db->prepare("UPDATE gwc.check_batches SET status = ?, finished_at = now(), message = ? WHERE id = ?")
                 ->execute([$status, $msg, $batchId]);
        $this->say("Verificati {$b['n_done']}: ok {$b['n_ok']}, avvisi {$b['n_warn']}, errori {$b['n_error']}" . ($msg ? " - $msg" : ''));
        return $this->batch($batchId);
    }

    private function lastBatchOf(string $key): ?int
    {
        $st = $this->db->prepare("SELECT last_batch_id FROM gwc.check_targets WHERE check_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    private function kind(array $t): string
    {
        return in_array($t['endpoint_type'], ServiceChecker::OGC, true) ? $t['endpoint_type'] : 'link';
    }

    private function save(int $batchId, string $key, array $r, array $endpoints): void
    {
        $this->db->beginTransaction();
        try {
            $isErr = $r['status'] === 'error';
            $this->db->prepare(
                "INSERT INTO gwc.check_targets (check_key, kind, url, last_check_at, last_status, last_ok_at, http_status, response_ms,
                        content_type, content_length, final_url, service_title, service_version, n_layers, note, n_checks, n_failures, last_batch_id)
                 VALUES (:k, :kind, :url, now(), :st, CASE WHEN :ok1 THEN now() END, :http, :ms, :ct, :cl, :fu, :ti, :ve, :nl, :no, 1,
                         CASE WHEN :err1 THEN 1 ELSE 0 END, :b)
                 ON CONFLICT (check_key) DO UPDATE SET kind = EXCLUDED.kind, url = EXCLUDED.url, last_check_at = now(),
                        last_status = EXCLUDED.last_status,
                        last_ok_at = CASE WHEN :ok2 THEN now() ELSE gwc.check_targets.last_ok_at END,
                        http_status = EXCLUDED.http_status, response_ms = EXCLUDED.response_ms, content_type = EXCLUDED.content_type,
                        content_length = EXCLUDED.content_length, final_url = EXCLUDED.final_url,
                        service_title = COALESCE(EXCLUDED.service_title, gwc.check_targets.service_title),
                        service_version = COALESCE(EXCLUDED.service_version, gwc.check_targets.service_version),
                        n_layers = EXCLUDED.n_layers, note = EXCLUDED.note, n_checks = gwc.check_targets.n_checks + 1,
                        n_failures = CASE WHEN :err2 THEN gwc.check_targets.n_failures + 1 ELSE 0 END,
                        last_batch_id = EXCLUDED.last_batch_id")
                ->execute([':k' => $key, ':kind' => $r['kind'], ':url' => $r['url'], ':st' => $r['status'],
                           ':ok1' => $isErr ? 'f' : 't', ':ok2' => $isErr ? 'f' : 't', ':http' => $r['http_status'], ':ms' => $r['ms'],
                           ':ct' => $r['content_type'] ? mb_substr($r['content_type'], 0, 200) : null, ':cl' => $r['content_length'],
                           ':fu' => $r['final_url'], ':ti' => $r['title'], ':ve' => $r['version'],
                           ':nl' => $r['layers'] !== null ? count($r['layers']) : null, ':no' => $r['note'],
                           ':err1' => $isErr ? 't' : 'f', ':err2' => $isErr ? 't' : 'f', ':b' => $batchId]);

            $ins = $this->db->prepare(
                "INSERT INTO gwc.endpoint_checks (endpoint_id, ok, http_status, response_ms, content_type, content_length, final_url,
                        capabilities_ok, error, status, check_key, batch_id, layer_found, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $upd = $this->db->prepare("UPDATE gwc.endpoints SET last_check_at = now(), last_check_ok = ?, last_check_status = ?,
                                              last_check_note = ? WHERE id = ?");
            foreach ($endpoints as $e) {
                $found = ServiceChecker::layerFound($e['layer_name'], $r['layers']);
                $status = $r['status'];
                $note = $r['note'];
                if ($found === false && $status !== 'error') {
                    $status = 'warn';
                    $note = trim("Il layer \"{$e['layer_name']}\" non c'e' piu' nel servizio" . ($note ? "; $note" : ''));
                }
                $ok = $status !== 'error';
                $ins->execute([$e['id'], $ok ? 't' : 'f', $r['http_status'], $r['ms'],
                               $r['content_type'] ? mb_substr($r['content_type'], 0, 200) : null, $r['content_length'], $r['final_url'],
                               $r['capabilities_ok'] === null ? null : ($r['capabilities_ok'] ? 't' : 'f'),
                               $status === 'error' ? $note : null, $status, $key, $batchId,
                               $found === null ? null : ($found ? 't' : 'f'), $note]);
                $upd->execute([$ok ? 't' : 'f', $status, $note, $e['id']]);
            }
            $col = ['ok' => 'n_ok', 'warn' => 'n_warn', 'error' => 'n_error'][$r['status']];
            $this->db->exec("UPDATE gwc.check_batches SET n_done = n_done + 1, $col = $col + 1 WHERE id = $batchId");
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Avvia il lotto in background con il PHP da riga di comando (cosi' la pagina non resta bloccata).
     * @return bool false se il PHP da riga di comando non e' stato trovato
     */
    public static function launchBackground(int $batchId): bool
    {
        $php = self::phpCli();
        if ($php === null) {
            return false;
        }
        $script = realpath(__DIR__ . '/../../bin/check.php');
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . " --batch=$batchId > NUL 2>&1";
            $p = popen($cmd, 'r');
            if ($p === false) {
                return false;
            }
            pclose($p);
            return true;
        }
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . " --batch=$batchId > /dev/null 2>&1 &", $o, $code);
        return $code === 0;
    }

    /** Percorso del PHP da riga di comando: config check.php_cli, altrimenti accanto all'interprete in uso. */
    public static function phpCli(): ?string
    {
        $cands = [];
        if ($c = \GeoWebCrawler\Config::get('check.php_cli')) {
            $cands[] = (string) $c;
        }
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            $cands[] = PHP_BINARY;
        }
        $win = PHP_OS_FAMILY === 'Windows';
        $cands[] = PHP_BINDIR . DIRECTORY_SEPARATOR . ($win ? 'php.exe' : 'php');
        if ($win) {
            $cands[] = dirname(PHP_BINARY) . '\\php.exe';
        }
        foreach ($cands as $c) {
            if ($c !== '' && is_file($c) && !preg_match('/httpd|apache|php-cgi|php-fpm/i', basename($c))) {
                return $c;
            }
        }
        return null;
    }
}
