<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use GeoWebCrawler\Config;
use GeoWebCrawler\Repository\ResourceRepository;
use PDO;

/** Esegue la raccolta su una sorgente e ne registra l'esito in gwc.harvest_runs. */
final class HarvestRunner
{
    /** @var array<string, callable():Harvester> */
    private array $harvesters;

    public function __construct(private PDO $db, ?array $harvesters = null)
    {
        $this->harvesters = $harvesters ?? self::defaultHarvesters();
    }

    /** Tipi di sorgente (gwc.source_types.code) che sanno essere raccolti. */
    public const SUPPORTED = ['csw', 'webpage', 'ogc_service', 'ckan', 'dcat', 'stac'];

    private static function defaultHarvesters(): array
    {
        return [
            'csw'         => fn() => new CswHarvester(),
            'webpage'     => fn() => new WebPageHarvester(),
            'ogc_service' => fn() => new OgcCapabilitiesHarvester(),
            'ckan'        => fn() => new CkanHarvester(),
            'dcat'        => fn() => new DcatHarvester(),
            'stac'        => fn() => new StacHarvester(),
        ];
    }

    /** Tipi di sorgente che sanno chiedere solo i record modificati dopo una data. */
    public const INCREMENTAL = ['csw', 'ckan'];

    /**
     * @param callable(string):void|null $echo riceve ogni riga di log (per la riga di comando)
     * @param bool $full true: raccolta completa anche se sarebbe possibile quella incrementale
     * @return array riga finale di gwc.harvest_runs
     */
    /**
     * Progressione a riga di comando (solo a video, non nel registro della raccolta):
     * 0 = niente, 1 = una riga ogni $progressEvery record, 2 = una riga per ogni record.
     */
    public int $verbosity = 0;
    public int $progressEvery = 100;

    public function run(int $sourceId, ?callable $echo = null, bool $full = false): array
    {
        $st = $this->db->prepare("SELECT * FROM gwc.sources WHERE id = ?");
        $st->execute([$sourceId]);
        $source = $st->fetch();
        if (!$source) {
            throw new \InvalidArgumentException("Sorgente $sourceId inesistente");
        }
        $source['params'] = json_decode($source['params'] ?: '{}', true) ?: [];
        $p = $source['params'];
        $max = max(1, (int) ($p['max_records'] ?? Config::get('harvest.max_records', 25000)));

        [$mode, $since] = $full ? ['full', null] : $this->chooseMode($source);
        $source['since'] = $since;

        $st = $this->db->prepare("INSERT INTO gwc.harvest_runs (source_id, mode, since) VALUES (?, ?, CAST(? AS timestamptz)) RETURNING id");
        $st->execute([$sourceId, $mode, $since]);
        $runId = (int) $st->fetchColumn();

        $lines = [];
        $log = function (string $msg) use (&$lines, $echo): void {
            $line = date('H:i:s') . ' ' . $msg;
            $lines[] = $line;
            if ($echo) {
                $echo($line);
            }
        };

        $found = $new = $updated = $failed = $dups = $removed = 0;
        $failedRefs = [];
        $status = 'ok';
        $message = null;
        $complete = false;
        $repo = new ResourceRepository($this->db);

        try {
            $factory = $this->harvesters[$source['source_type']] ?? null;
            if ($factory === null) {
                throw new \RuntimeException("Il tipo di sorgente '{$source['source_type']}' non e' ancora supportato");
            }
            $log("Inizio raccolta: {$source['name']} ({$source['url']})");
            if ($mode === 'incremental') {
                $log("Raccolta incrementale: solo i record modificati dal $since (ogni "
                    . self::fullEvery($p) . " giorni se ne fa una completa)");
            }
            $gen = $factory()->harvest($source, $log);
            $t0 = microtime(true);
            foreach ($gen as $record) {
                $found++;
                $before = [$new, $failed];
                // ogni record in una transazione: un record difettoso non blocca gli altri
                $this->db->beginTransaction();
                try {
                    $res = $repo->save($record, $sourceId, $runId);
                    $this->db->commit();
                    $res['status'] === 'new' ? $new++ : $updated++;
                    $dups += $res['duplicates'];
                } catch (\Throwable $e) {
                    $this->db->rollBack();
                    $failed++;
                    $failedRefs[] = $record->recordRef;
                    $log("Record non salvato ({$record->title}): " . $e->getMessage());
                }
                if ($echo && $this->verbosity >= 2) {
                    $what = $failed > $before[1] ? 'ERRORE' : ($new > $before[0] ? 'nuovo ' : 'aggiorn.');
                    $echo(sprintf('%s   %6d %s %s', date('H:i:s'), $found, $what, mb_substr($record->title, 0, 100)));
                } elseif ($echo && $this->verbosity === 1 && $found % max(1, $this->progressEvery) === 0) {
                    $secs = max(0.001, microtime(true) - $t0);
                    $echo(sprintf('%s   ... %d record letti (nuovi %d, aggiornati %d, errori %d, possibili duplicati %d) - %.1f record/s',
                                  date('H:i:s'), $found, $new, $updated, $failed, $dups, $found / $secs));
                }
            }
            if ($failed > 0) {
                $status = 'partial';
            }
            // sorgente letta tutta: si possono riconoscere i record che non ci sono piu'
            $complete = $mode === 'full' && $gen->getReturn() !== false && $found < $max;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $status = $found > 0 ? 'partial' : 'error';
            $message = $e->getMessage();
            $log('Interrotta: ' . $message);
        }

        if ($complete) {
            // i record non salvati per errore non vanno considerati rimossi
            if ($failedRefs) {
                $t = $this->db->prepare("UPDATE gwc.resource_sources SET last_run_id = ? WHERE source_id = ? AND record_ref = ?");
                foreach ($failedRefs as $ref) {
                    $t->execute([$runId, $sourceId, $ref]);
                }
            }
            $st = $this->db->prepare("SELECT gwc.mark_removed(?, ?)");
            $st->execute([$sourceId, $runId]);
            $removed = (int) $st->fetchColumn();
            if ($removed > 0) {
                $log("Risorse non piu' presenti nella sorgente (segnate come rimosse): $removed");
            }
        } elseif ($mode === 'full' && $status !== 'error') {
            $log('Sorgente non letta per intero (limite di record o errori): i record mancanti non vengono segnati come rimossi');
        }

        // collegamenti servizio-dataset e nuove sorgenti citate nei record
        if ($found > 0) {
            try {
                $n = (int) $this->db->query("SELECT gwc.resolve_links()")->fetchColumn();
                if ($n > 0) {
                    $log("Nuovi collegamenti tra servizi e dataset: $n");
                }
                $n = (new \GeoWebCrawler\Repository\SourceDiscovery($this->db))->discover();
                if ($n > 0) {
                    $log("Nuove sorgenti proposte (da approvare nella pagina Sorgenti): $n");
                }
            } catch (\Throwable $e) {
                $log('Collegamenti e scoperta sorgenti non riusciti: ' . $e->getMessage());
            }
        }

        $summary = "Trovati $found, nuovi $new, aggiornati $updated, non salvati $failed, rimossi $removed, possibili duplicati $dups";
        $log($summary);
        $message = $message ? "$message. $summary" : $summary;

        $this->db->prepare(
            "UPDATE gwc.harvest_runs SET finished_at = now(), status = ?, records_found = ?, records_new = ?,
                    records_updated = ?, records_removed = ?, complete = ?, message = ?, log = ? WHERE id = ?"
        )->execute([$status, $found, $new, $updated, $removed, $complete ? 'true' : 'false', $message,
                    implode("\n", array_slice($lines, -2000)), $runId]);
        $this->db->prepare("UPDATE gwc.sources SET last_harvest_at = now() WHERE id = ?")->execute([$sourceId]);

        // il catalogo non accetta il filtro per data: si disattiva l'incrementale e si rifa' tutto
        if ($mode === 'incremental' && $status === 'error') {
            $echo && $echo(date('H:i:s') . ' La raccolta incrementale non funziona con questa sorgente: la disattivo e rifaccio la raccolta completa');
            $this->db->prepare("UPDATE gwc.sources SET params = params || '{\"incremental\": false}'::jsonb WHERE id = ?")
                ->execute([$sourceId]);
            return $this->run($sourceId, $echo, true);
        }

        $st = $this->db->prepare("SELECT * FROM gwc.harvest_runs WHERE id = ?");
        $st->execute([$runId]);
        return $st->fetch();
    }

    /** @return array{0:'full'|'incremental', 1:?string} modalita' e data da cui leggere le modifiche */
    private function chooseMode(array $source): array
    {
        $p = $source['params'];
        if (!in_array($source['source_type'], self::INCREMENTAL, true) || !($p['incremental'] ?? true)) {
            return ['full', null];
        }
        // serve una raccolta completa recente e una raccolta riuscita da cui ripartire
        $st = $this->db->prepare("SELECT
                (SELECT max(started_at) FROM gwc.harvest_runs WHERE source_id = :id1 AND mode = 'full' AND complete
                    AND started_at > now() - make_interval(days => :days)) AS last_full,
                (SELECT max(started_at) FROM gwc.harvest_runs WHERE source_id = :id2 AND status = 'ok') AS last_ok");
        $st->execute([':id1' => $source['id'], ':id2' => $source['id'], ':days' => self::fullEvery($p)]);
        $row = $st->fetch();
        if (!$row['last_full'] || !$row['last_ok']) {
            return ['full', null];
        }
        // un giorno di margine: gli orologi dei server non sono sempre allineati
        return ['incremental', date('Y-m-d', strtotime((string) $row['last_ok']) - 86400)];
    }

    private static function fullEvery(array $p): int
    {
        return max(1, (int) ($p['full_every_days'] ?? 30));
    }
}
