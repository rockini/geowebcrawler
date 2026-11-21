<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest\Rdf;

/**
 * Grafo RDF minimo in memoria: soggetto -> predicato -> lista di oggetti.
 * Un oggetto e' ['uri' => ...] (anche nodi anonimi, con prefisso "_:")
 * oppure ['v' => valore, 'lang' => ?string, 'dt' => ?string] per i letterali.
 */
final class Graph
{
    public const RDF   = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    public const RDFS  = 'http://www.w3.org/2000/01/rdf-schema#';
    public const DCAT  = 'http://www.w3.org/ns/dcat#';
    public const DCT   = 'http://purl.org/dc/terms/';
    public const FOAF  = 'http://xmlns.com/foaf/0.1/';
    public const VCARD = 'http://www.w3.org/2006/vcard/ns#';
    public const LOCN  = 'http://www.w3.org/ns/locn#';
    public const SKOS  = 'http://www.w3.org/2004/02/skos/core#';
    public const HYDRA = 'http://www.w3.org/ns/hydra/core#';
    public const ADMS  = 'http://www.w3.org/ns/adms#';

    /** @var array<string, array<string, array<int, array>>> */
    public array $triples = [];

    public function add(string $s, string $p, array $o): void
    {
        $this->triples[$s][$p][] = $o;
    }

    /** @return array<int, array> */
    public function objects(string $s, string $p): array
    {
        return $this->triples[$s][$p] ?? [];
    }

    /** Soggetti che hanno il tipo indicato. */
    public function subjectsOfType(string $type): array
    {
        $out = [];
        foreach ($this->triples as $s => $props) {
            foreach ($props[self::RDF . 'type'] ?? [] as $o) {
                if (($o['uri'] ?? null) === $type) {
                    $out[] = $s;
                    break;
                }
            }
        }
        return $out;
    }

    public function hasType(string $s, string $type): bool
    {
        foreach ($this->objects($s, self::RDF . 'type') as $o) {
            if (($o['uri'] ?? null) === $type) {
                return true;
            }
        }
        return false;
    }

    /** Un letterale, preferendo le lingue indicate (poi senza lingua, poi qualsiasi). */
    public function literal(string $s, string $p, array $langs = ['it', 'en']): ?string
    {
        $all = $this->literals($s, $p, $langs);
        return $all[0] ?? null;
    }

    /** Tutti i letterali nella lingua preferita disponibile. */
    public function literals(string $s, string $p, array $langs = ['it', 'en']): array
    {
        $byLang = [];
        foreach ($this->objects($s, $p) as $o) {
            if (isset($o['v']) && trim($o['v']) !== '') {
                $byLang[strtolower(substr((string) ($o['lang'] ?? ''), 0, 2))][] = trim(preg_replace('/\s+/u', ' ', $o['v']));
            }
        }
        foreach ([...$langs, ''] as $l) {
            if (!empty($byLang[$l])) {
                return array_values(array_unique($byLang[$l]));
            }
        }
        return $byLang ? array_values(array_unique(array_merge(...array_values($byLang)))) : [];
    }

    /** URI (non anonimi) e nodi collegati, come stringhe. */
    public function uris(string $s, string $p): array
    {
        return array_values(array_filter(array_map(fn($o) => $o['uri'] ?? null, $this->objects($s, $p))));
    }

    public function uri(string $s, string $p): ?string
    {
        return $this->uris($s, $p)[0] ?? null;
    }

    /** Valore di un oggetto come testo: letterale, oppure URI. */
    public function value(string $s, string $p): ?string
    {
        foreach ($this->objects($s, $p) as $o) {
            if (isset($o['v']) && trim($o['v']) !== '') {
                return trim($o['v']);
            }
            if (isset($o['uri']) && !str_starts_with($o['uri'], '_:')) {
                return $o['uri'];
            }
        }
        return null;
    }

    /** Etichetta leggibile di un nodo (titolo, nome, prefLabel...). */
    public function label(string $node): ?string
    {
        foreach ([self::FOAF . 'name', self::VCARD . 'fn', self::VCARD . 'organization-name', self::SKOS . 'prefLabel',
                  self::DCT . 'title', self::RDFS . 'label'] as $p) {
            if ($v = $this->literal($node, $p)) {
                return $v;
            }
        }
        return null;
    }
}
