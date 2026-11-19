<?php
declare(strict_types=1);

namespace GeoWebCrawler\Repository;

use PDO;

final class SourceRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Inserisce una sorgente o aggiorna quella con lo stesso URL (confronto normalizzato).
     * Restituisce [id, true se nuova].
     */
    public function upsert(string $name, string $type, string $url, array $params = [],
                           bool $enabled = true, string $origin = 'ui', ?string $description = null): array
    {
        $sql = "INSERT INTO gwc.sources (name, source_type, url, params, enabled, origin, description)
                VALUES (:name, :type, :url, CAST(:params AS jsonb), :enabled, :origin, :description)
                ON CONFLICT (url_key) DO UPDATE
                   SET name = EXCLUDED.name, source_type = EXCLUDED.source_type,
                       params = EXCLUDED.params, enabled = EXCLUDED.enabled,
                       description = COALESCE(EXCLUDED.description, gwc.sources.description)
                RETURNING id, (xmax = 0) AS inserted";
        $st = $this->db->prepare($sql);
        $st->bindValue(':name', $name);
        $st->bindValue(':type', $type);
        $st->bindValue(':url', $url);
        $st->bindValue(':params', json_encode((object) $params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $st->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $st->bindValue(':origin', $origin);
        $st->bindValue(':description', $description);
        $st->execute();
        $row = $st->fetch();
        return [(int) $row['id'], (bool) $row['inserted']];
    }

    public function all(): array
    {
        return $this->db->query(
            "SELECT s.*, (SELECT count(*) FROM gwc.resource_sources rs WHERE rs.source_id = s.id) AS n_resources
               FROM gwc.sources s ORDER BY s.name"
        )->fetchAll();
    }
}
