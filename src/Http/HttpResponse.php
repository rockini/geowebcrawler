<?php
declare(strict_types=1);

namespace GeoWebCrawler\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $contentType,
        public readonly string $finalUrl,
        public readonly int $timeMs,
        public readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /** Descrizione breve del problema, per i log. */
    public function problem(): string
    {
        return $this->error ?? "HTTP {$this->status}";
    }
}
