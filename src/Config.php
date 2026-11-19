<?php
declare(strict_types=1);

namespace GeoWebCrawler;

final class Config
{
    private static array $values = [];

    public static function load(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException(
                "File di configurazione mancante: $file\nCopia config/config.example.php in config/config.php e modificalo."
            );
        }
        self::$values = require $file;
        return self::$values;
    }

    /** Legge un valore con notazione a punti, es. Config::get('db.host') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }
}
