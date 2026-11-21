<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

final class Endpoint
{
    public function __construct(
        public string $url,
        public string $type,                 // codice di gwc.endpoint_types
        public ?string $format = null,       // codice di gwc.formats
        public ?string $protocol = null,
        public ?string $serviceVersion = null,
        public ?string $layerName = null,
        public ?string $label = null,
    ) {}
}
