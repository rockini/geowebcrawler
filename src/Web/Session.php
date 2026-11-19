<?php
declare(strict_types=1);

namespace GeoWebCrawler\Web;

final class Session
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    public static function checkCsrf(): void
    {
        if (!hash_equals(self::csrfToken(), (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            exit('Richiesta non valida (token scaduto): ricarica la pagina e riprova.');
        }
    }

    public static function flash(string $msg, string $type = 'ok'): void
    {
        $_SESSION['flash'][] = [$type, $msg];
    }

    public static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
