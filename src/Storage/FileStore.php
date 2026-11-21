<?php
declare(strict_types=1);

namespace GeoWebCrawler\Storage;

use GeoWebCrawler\Config;
use PDO;

/**
 * File esterni al database. I dati grandi si scrivono in file sotto la cartella "storage_dir"
 * (config.php); nel database (gwc.files) restano solo percorso relativo, dimensione, sha256 e poche
 * informazioni descrittive. Tutti i passi del progetto che producono dati grandi usano questa classe.
 */
final class FileStore
{
    private string $root;

    public function __construct(private PDO $db, ?string $root = null)
    {
        $root ??= (string) Config::get('storage_dir', __DIR__ . '/../../storage');
        if (!is_dir($root) && !@mkdir($root, 0775, true)) {
            throw new \RuntimeException("Cartella dei file non disponibile: $root");
        }
        $this->root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Percorso assoluto di un percorso relativo (che non puo' uscire dalla cartella). */
    public function absolute(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
            throw new \InvalidArgumentException("Percorso non valido: $relative");
        }
        return $this->root . '/' . $relative;
    }

    /**
     * Prepara un nuovo percorso relativo libero, es. newPath('sparql/1/12', 'Luoghi della cultura', 'csv')
     * -> 'sparql/1/12/20261121-1030-luoghi-della-cultura.csv'
     */
    public function newPath(string $dir, string $name, string $ext): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(self::ascii($name))), '-') ?: 'dati';
        $base = trim(str_replace('\\', '/', $dir), '/') . '/' . date('Ymd-His') . '-' . substr($slug, 0, 60);
        $rel = "$base.$ext";
        for ($i = 2; is_file($this->absolute($rel)); $i++) {
            $rel = "$base-$i.$ext";
        }
        $abs = $this->absolute($rel);
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0775, true)) {
            throw new \RuntimeException('Impossibile creare la cartella ' . dirname($abs));
        }
        return $rel;
    }

    /** Registra (o aggiorna) un file gia' scritto. @return int id in gwc.files */
    public function register(string $relative, string $kind, ?string $mediaType = null, ?int $rows = null,
                             array $meta = [], ?string $originalName = null): int
    {
        $abs = $this->absolute($relative);
        if (!is_file($abs)) {
            throw new \RuntimeException("File non trovato: $abs");
        }
        $st = $this->db->prepare(
            "INSERT INTO gwc.files (kind, path, original_name, media_type, size_bytes, sha256, n_rows, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb))
             ON CONFLICT (path) DO UPDATE SET size_bytes = EXCLUDED.size_bytes, sha256 = EXCLUDED.sha256,
                    n_rows = EXCLUDED.n_rows, meta = EXCLUDED.meta, media_type = EXCLUDED.media_type
             RETURNING id");
        $st->execute([$kind, ltrim($relative, '/'), $originalName, $mediaType, filesize($abs), hash_file('sha256', $abs),
                      $rows, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}']);
        return (int) $st->fetchColumn();
    }

    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM gwc.files WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Cancella file e riferimento. */
    public function delete(int $id): void
    {
        if ($f = $this->get($id)) {
            @unlink($this->absolute($f['path']));
            $this->db->prepare("DELETE FROM gwc.files WHERE id = ?")->execute([$id]);
        }
    }

    private static function ascii(string $s): string
    {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $s) : false;
        return $t !== false ? $t : $s;
    }
}
