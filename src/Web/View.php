<?php
declare(strict_types=1);

namespace GeoWebCrawler\Web;

/** Rendering dei template in templates/, dentro il layout comune. */
final class View
{
    public static function render(string $template, array $vars = [], string $title = 'GeoWebCrawler'): void
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/../../templates/' . $template . '.php';
        $content = ob_get_clean();
        $flash = Session::takeFlash();
        require __DIR__ . '/../../templates/layout.php';
    }

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** URL interno, es. View::url('resource', ['id' => 3]) */
    public static function url(string $page, array $params = []): string
    {
        return '?' . http_build_query(['p' => $page] + $params);
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e(Session::csrfToken()) . '">';
    }

    public static function date(?string $ts, bool $time = false): string
    {
        if (!$ts) {
            return '–';
        }
        $t = strtotime($ts);
        return $t ? date($time ? 'd/m/Y H:i' : 'd/m/Y', $t) : self::e($ts);
    }
}
