<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

interface Harvester
{
    /**
     * Legge la sorgente e restituisce i record trovati, uno alla volta.
     * @param array $source riga di gwc.sources (params gia' decodificato in array)
     * @param callable(string):void $log
     * @return \Generator<Record>
     */
    public function harvest(array $source, callable $log): \Generator;
}
