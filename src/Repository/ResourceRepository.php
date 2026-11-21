<?php
declare(strict_types=1);

namespace GeoWebCrawler\Repository;

use GeoWebCrawler\Harvest\Record;
use PDO;

/** Salva nel database i record raccolti, evitando di duplicarli. */
final class ResourceRepository
{
    private ?array $licenses = null;
    private ?array $formats = null;

    public function __construct(private PDO $db) {}

    /**
     * Inserisce o aggiorna la risorsa corrispondente al record.
     * Un record gia' visto nella stessa sorgente (stesso recordRef) aggiorna la risorsa esistente;
     * se nel frattempo era stata unita a un'altra, si aggiorna quella tenuta.
     *
     * @return array{id:int, status:'new'|'updated', duplicates:int}
     */
    public function save(Record $r, int $sourceId, int $runId): array
    {
        $st = $this->db->prepare(
            "SELECT r.id, r.status, r.canonical_id
               FROM gwc.resource_sources rs JOIN gwc.resources r ON r.id = rs.resource_id
              WHERE rs.source_id = ? AND rs.record_ref = ?
              ORDER BY (r.status = 'duplicate'), r.id LIMIT 1");
        $st->execute([$sourceId, $r->recordRef]);
        $existing = $st->fetch();

        if ($existing === false) {
            $id = $this->insertResource($r);
            $status = 'new';
        } elseif ($existing['status'] === 'duplicate') {
            // la risorsa era stata unita: si aggiungono solo i nuovi link a quella tenuta
            $id = (int) $existing['canonical_id'];
            $this->db->prepare("UPDATE gwc.resources SET last_seen_at = now() WHERE id = ?")->execute([$id]);
            $status = 'updated';
        } else {
            $id = (int) $existing['id'];
            $this->updateResource($id, $r);
            $status = 'updated';
        }

        $this->saveParties($id, $r);
        $this->saveEndpoints($id, $r);
        $this->db->prepare(
            "INSERT INTO gwc.resource_sources (resource_id, source_id, record_ref, first_run_id, last_run_id)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (resource_id, source_id, record_ref)
             DO UPDATE SET last_run_id = EXCLUDED.last_run_id, last_seen_at = now(), removed_at = NULL"
        )->execute([$id, $sourceId, $r->recordRef, $runId, $runId]);
        // era stata segnata come rimossa dalla sorgente ed e' ricomparsa
        $this->db->prepare("UPDATE gwc.resources SET status = 'active' WHERE id = ? AND status = 'removed'")->execute([$id]);

        // servizio: riferimenti ai dataset pubblicati (risolti a fine raccolta con gwc.resolve_links)
        if ($r->servesRefs) {
            $ref = $this->db->prepare("INSERT INTO gwc.resource_link_refs (resource_id, ref) VALUES (?, ?) ON CONFLICT DO NOTHING");
            foreach ($r->servesRefs as $u) {
                $ref->execute([$id, mb_substr($u, 0, 2000)]);
            }
        }
        $this->db->prepare("SELECT gwc.assign_themes(?)")->execute([$id]);

        $dup = $this->db->prepare("SELECT gwc.detect_duplicates(?)");
        $dup->execute([$id]);

        return ['id' => $id, 'status' => $status, 'duplicates' => (int) $dup->fetchColumn()];
    }

    private const FIELDS = [
        'resource_type', 'external_id', 'title', 'abstract', 'keywords', 'topic_categories', 'language',
        'date_created', 'date_published', 'date_revised', 'metadata_date', 'update_frequency',
        'license_id', 'license_text', 'access_constraints', 'crs', 'bbox',
        'metadata_standard', 'metadata_url', 'raw_metadata',
    ];

    private function values(Record $r): array
    {
        return [
            'resource_type'      => $r->resourceType,
            'external_id'        => $r->externalId,
            'title'              => $r->title,
            'abstract'           => $r->abstract,
            'keywords'           => self::pgArray($r->keywords),
            'topic_categories'   => self::pgArray($r->topicCategories),
            'language'           => $r->language,
            'date_created'       => $r->dateCreated,
            'date_published'     => $r->datePublished,
            'date_revised'       => $r->dateRevised,
            'metadata_date'      => $r->metadataDate,
            'update_frequency'   => $r->updateFrequency,
            'license_id'         => $this->licenseId($r->licenseText),
            'license_text'       => $r->licenseText,
            'access_constraints' => $r->accessConstraints,
            'crs'                => self::pgArray(array_map('strval', $r->crs)),
            'bbox'               => $r->bbox ? self::bboxWkt($r->bbox) : null,
            'metadata_standard'  => $r->metadataStandard,
            'metadata_url'       => $r->metadataUrl,
            'raw_metadata'       => $r->rawMetadata,
        ];
    }

    /** Espressione SQL per ogni campo (conversioni di tipo). */
    private static function expr(string $f): string
    {
        return match ($f) {
            'keywords', 'topic_categories' => "CAST(:$f AS text[])",
            'crs'           => "CAST(:$f AS integer[])",
            'date_created', 'date_published', 'date_revised' => "CAST(:$f AS date)",
            'metadata_date' => "CAST(:$f AS timestamptz)",
            'license_id'    => "CAST(:$f AS integer)",
            'bbox'          => "ST_GeomFromText(:$f, 4326)",
            default         => ":$f",
        };
    }

    private function bind(\PDOStatement $st, array $v): void
    {
        foreach ($v as $k => $val) {
            $st->bindValue(":$k", $val, $val === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
    }

    private function insertResource(Record $r): int
    {
        $cols = implode(', ', self::FIELDS);
        $vals = implode(', ', array_map([self::class, 'expr'], self::FIELDS));
        $st = $this->db->prepare("INSERT INTO gwc.resources ($cols) VALUES ($vals) RETURNING id");
        $this->bind($st, $this->values($r));
        $st->execute();
        return (int) $st->fetchColumn();
    }

    private function updateResource(int $id, Record $r): void
    {
        $set = implode(', ', array_map(fn($f) => "$f = " . self::expr($f), self::FIELDS));
        $st = $this->db->prepare("UPDATE gwc.resources SET $set, last_seen_at = now() WHERE id = :id");
        $this->bind($st, $this->values($r));
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
    }

    private function saveParties(int $id, Record $r): void
    {
        $org = $this->db->prepare(
            "INSERT INTO gwc.organizations (name, email, url) VALUES (?, ?, ?)
             ON CONFLICT (name_key) DO UPDATE
                SET email = COALESCE(gwc.organizations.email, EXCLUDED.email),
                    url   = COALESCE(gwc.organizations.url, EXCLUDED.url)
             RETURNING id");
        $link = $this->db->prepare(
            "INSERT INTO gwc.resource_parties (resource_id, organization_id, role) VALUES (?, ?, ?)
             ON CONFLICT DO NOTHING");
        foreach ($r->parties as $p) {
            // nomi senza lettere o cifre non sono utilizzabili
            if (!preg_match('/[\p{L}\p{N}]/u', $p['name'])) {
                continue;
            }
            $org->execute([mb_substr($p['name'], 0, 300), $p['email'], $p['url']]);
            $link->execute([$id, (int) $org->fetchColumn(), $p['role']]);
        }
    }

    private function saveEndpoints(int $id, Record $r): void
    {
        $formats = $this->formats();
        $st = $this->db->prepare(
            "INSERT INTO gwc.endpoints (resource_id, endpoint_type, url, format_id, protocol, service_version, layer_name, label)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT ON CONSTRAINT endpoints_resource_url_layer_key DO UPDATE
                SET endpoint_type   = EXCLUDED.endpoint_type,
                    format_id       = COALESCE(EXCLUDED.format_id, gwc.endpoints.format_id),
                    protocol        = COALESCE(EXCLUDED.protocol, gwc.endpoints.protocol),
                    service_version = COALESCE(EXCLUDED.service_version, gwc.endpoints.service_version),
                    label           = COALESCE(EXCLUDED.label, gwc.endpoints.label)");
        foreach ($r->endpoints as $e) {
            $st->execute([
                $id, $e->type, $e->url, $e->format !== null ? ($formats[$e->format] ?? null) : null,
                $e->protocol, $e->serviceVersion, $e->layerName, $e->label !== null ? mb_substr($e->label, 0, 500) : null,
            ]);
        }
    }

    /** Riconosce la licenza dal testo dei metadati usando codici, URL e varianti della tabella licenses. */
    public function licenseId(?string $text): ?int
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $t = ' ' . strtolower(preg_replace('/\s+/', ' ', $text)) . ' ';
        // vince la variante piu' lunga trovata: cosi' "cc-by-sa-4.0" non viene preso per "cc-by"
        $best = null;
        $bestLen = 0;
        foreach ($this->licenses() as $l) {
            foreach ($l['needles'] as $needle) {
                if (strlen($needle) > $bestLen && str_contains($t, $needle)) {
                    $best = $l['id'];
                    $bestLen = strlen($needle);
                }
            }
        }
        return $best;
    }

    private function licenses(): array
    {
        if ($this->licenses === null) {
            $this->licenses = [];
            $rows = $this->db->query("SELECT id, code, url, aliases::text AS aliases FROM gwc.licenses
                                       WHERE code NOT IN ('unknown','restricted')")->fetchAll();
            foreach ($rows as $row) {
                $needles = [strtolower($row['code']), strtolower(str_replace('-', ' ', $row['code']))];
                if ($row['url']) {
                    $needles[] = strtolower(preg_replace('~^https?://(www\.)?~', '', rtrim($row['url'], '/')));
                }
                foreach (self::parsePgArray($row['aliases']) as $a) {
                    $needles[] = strtolower($a);
                }
                // per evitare che "cc by" riconosca anche "cc by-sa", le varianti piu' lunghe vanno provate prima
                usort($needles, fn($a, $b) => strlen($b) <=> strlen($a));
                $this->licenses[] = ['id' => (int) $row['id'], 'needles' => array_unique($needles),
                                     'len' => max(array_map('strlen', $needles))];
            }
            usort($this->licenses, fn($a, $b) => $b['len'] <=> $a['len']);
        }
        return $this->licenses;
    }

    private function formats(): array
    {
        return $this->formats ??= $this->db->query("SELECT code, id FROM gwc.formats")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** [ovest, sud, est, nord] -> poligono WKT */
    private static function bboxWkt(array $b): string
    {
        [$w, $s, $e, $n] = array_map(fn($x) => sprintf('%.8F', $x), $b);
        return "POLYGON(($w $s,$e $s,$e $n,$w $n,$w $s))";
    }

    /** Converte un array PHP di stringhe in un letterale array di PostgreSQL. */
    public static function pgArray(array $values): string
    {
        $items = array_map(fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"', array_values($values));
        return '{' . implode(',', $items) . '}';
    }

    public static function parsePgArray(?string $s): array
    {
        if ($s === null || $s === '{}' || $s === '') {
            return [];
        }
        $s = substr($s, 1, -1);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $s, $m, PREG_SET_ORDER);
        return array_map(fn($x) => isset($x[2]) && $x[2] !== '' ? $x[2] : stripcslashes($x[1]), $m);
    }
}
