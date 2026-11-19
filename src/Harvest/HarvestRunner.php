<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

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

    /**
     * @param callable(string):void|null $echo riceve ogni riga di log (per la riga di comando)
     * @return array riga finale di gwc.harvest_runs
     */
    public function run(int $sourceId, ?callable $echo = null): array
    {
        $st = $this->db->prepare("SELECT * FROM gwc.sources WHERE id = ?");
        $st->execute([$sourceId]);
        $source = $st->fetch();
        if (!$source) {
            throw new \InvalidArgumentException("Sorgente $sourceId inesistente");
        }
        $source['params'] = json_decode($source['params'] ?: '{}', true) ?: [];

        $st = $this->db->prepare("INSERT INTO gwc.harvest_runs (source_id) VALUES (?) RETURNING id");
        $st->execute([$sourceId]);
        $runId = (int) $st->fetchColumn();

        $lines = [];
        $log = function (string $msg) use (&$lines, $echo): void {
            $line = date('H:i:s') . ' ' . $msg;
            $lines[] = $line;
            if ($echo) {
                $echo($line);
            }
        };

        $found = $new = $updated = $failed = $dups = 0;
        $status = 'ok';
        $message = null;
        $repo = new ResourceRepository($this->db);

        try {
            $factory = $this->harvesters[$source['source_type']] ?? null;
            if ($factory === null) {
                throw new \RuntimeException("Il tipo di sorgente '{$source['source_type']}' non e' ancora supportato");
            }
            $log("Inizio raccolta: {$source['name']} ({$source['url']})");
            foreach ($factory()->harvest($source, $log) as $record) {
                $found++;
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
                    $log("Record non salvato ({$record->title}): " . $e->getMessage());
                }
            }
            if ($failed > 0) {
                $status = 'partial';
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $status = $found > 0 ? 'partial' : 'error';
            $message = $e->getMessage();
            $log('Interrotta: ' . $message);
        }

        $summary = "Trovati $found, nuovi $new, aggiornati $updated, non salvati $failed, possibili duplicati $dups";
        $log($summary);
        $message = $message ? "$message. $summary" : $summary;

        $this->db->prepare(
            "UPDATE gwc.harvest_runs SET finished_at = now(), status = ?, records_found = ?, records_new = ?,
                    records_updated = ?, message = ?, log = ? WHERE id = ?"
        )->execute([$status, $found, $new, $updated, $message, implode("\n", array_slice($lines, -2000)), $runId]);
        $this->db->prepare("UPDATE gwc.sources SET last_harvest_at = now() WHERE id = ?")->execute([$sourceId]);

        $st = $this->db->prepare("SELECT * FROM gwc.harvest_runs WHERE id = ?");
        $st->execute([$runId]);
        return $st->fetch();
    }
}
