<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

/**
 * Un riferimento a dati trovato in una sorgente, prima di essere salvato.
 * Tutti i campi tranne recordRef e title sono facoltativi.
 */
final class Record
{
    public string $resourceType = 'dataset';   // dataset | series | service | other
    public ?string $externalId = null;
    public ?string $abstract = null;
    /** @var string[] */
    public array $keywords = [];
    /** @var string[] */
    public array $topicCategories = [];
    public ?string $language = null;
    public ?string $dateCreated = null;         // YYYY-MM-DD
    public ?string $datePublished = null;
    public ?string $dateRevised = null;
    public ?string $metadataDate = null;
    public ?string $updateFrequency = null;
    public ?string $licenseText = null;
    public ?string $accessConstraints = null;
    /** @var int[] codici EPSG */
    public array $crs = [];
    /** @var array{0:float,1:float,2:float,3:float}|null [ovest, sud, est, nord] in WGS84 */
    public ?array $bbox = null;
    public ?string $metadataStandard = null;
    public ?string $metadataUrl = null;
    public ?string $rawMetadata = null;
    /** @var array<int, array{name:string, role:string, email:?string, url:?string}> */
    public array $parties = [];
    /** @var Endpoint[] */
    public array $endpoints = [];
    /** @var string[] per un servizio: identificativi o URL dei dataset che pubblica (srv:operatesOn, dcat:servesDataset) */
    public array $servesRefs = [];

    public function __construct(
        public string $recordRef,   // identifica il record nella sorgente (id CSW o URL)
        public string $title,
    ) {}

    public function addParty(string $name, string $role = 'author', ?string $email = null, ?string $url = null): void
    {
        $name = trim($name);
        if ($name !== '') {
            $this->parties[] = ['name' => $name, 'role' => $role, 'email' => $email, 'url' => $url];
        }
    }

    public function addEndpoint(?Endpoint $e): void
    {
        if ($e !== null) {
            $this->endpoints[] = $e;
        }
    }
}
