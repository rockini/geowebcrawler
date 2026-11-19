<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest\Rdf;

use DOMElement;
use DOMNode;

/**
 * Lettore RDF/XML essenziale, sufficiente per i cataloghi DCAT-AP / GeoDCAT-AP:
 * gestisce rdf:about, rdf:resource, rdf:nodeID, nodi annidati, rdf:parseType="Resource"
 * e "Literal", attributi-proprieta', xml:lang e rdf:datatype.
 * Non gestisce rdf:li/contenitori e la reificazione, che questi cataloghi non usano.
 */
final class RdfXmlParser
{
    private const RDF = Graph::RDF;
    private const XML = 'http://www.w3.org/XML/1998/namespace';

    private int $blank = 0;

    public function __construct(private Graph $graph = new Graph()) {}

    public function graph(): Graph
    {
        return $this->graph;
    }

    /** Analizza un documento o un frammento (rdf:RDF oppure direttamente un nodo come dcat:Dataset). */
    public function parse(DOMElement $el): Graph
    {
        if ($el->namespaceURI === self::RDF && $el->localName === 'RDF') {
            foreach (self::children($el) as $child) {
                $this->node($child, $this->lang($el));
            }
        } else {
            $this->node($el, $this->lang($el));
        }
        return $this->graph;
    }

    /** Elemento-nodo: restituisce l'identificativo del soggetto. */
    private function node(DOMElement $el, ?string $lang): string
    {
        $lang = $this->lang($el) ?? $lang;
        $s = $this->subject($el);
        if (!($el->namespaceURI === self::RDF && $el->localName === 'Description')) {
            $this->graph->add($s, self::RDF . 'type', ['uri' => $el->namespaceURI . $el->localName]);
        }
        $this->propertyAttributes($el, $s, $lang);
        foreach (self::children($el) as $prop) {
            $this->property($prop, $s, $lang);
        }
        return $s;
    }

    private function property(DOMElement $el, string $s, ?string $lang): void
    {
        $p = $el->namespaceURI . $el->localName;
        $lang = $this->lang($el) ?? $lang;

        if ($el->hasAttributeNS(self::RDF, 'resource')) {
            $this->graph->add($s, $p, ['uri' => $this->resolve($el, $el->getAttributeNS(self::RDF, 'resource'))]);
            return;
        }
        if ($el->hasAttributeNS(self::RDF, 'nodeID') && !self::children($el)) {
            $this->graph->add($s, $p, ['uri' => '_:' . $el->getAttributeNS(self::RDF, 'nodeID')]);
            return;
        }
        $parseType = $el->getAttributeNS(self::RDF, 'parseType');
        if ($parseType === 'Resource') {
            $b = '_:b' . (++$this->blank);
            $this->graph->add($s, $p, ['uri' => $b]);
            foreach (self::children($el) as $child) {
                $this->property($child, $b, $lang);
            }
            return;
        }
        if ($parseType === 'Literal') {
            $xml = '';
            foreach ($el->childNodes as $c) {
                $xml .= $el->ownerDocument->saveXML($c);
            }
            $this->graph->add($s, $p, ['v' => $xml, 'lang' => null, 'dt' => self::RDF . 'XMLLiteral']);
            return;
        }
        $children = self::children($el);
        if ($children) {
            foreach ($children as $child) {
                $this->graph->add($s, $p, ['uri' => $this->node($child, $lang)]);
            }
            return;
        }
        // proprieta' con soli attributi-proprieta': nodo anonimo
        if (self::propertyAttrs($el)) {
            $b = '_:b' . (++$this->blank);
            $this->graph->add($s, $p, ['uri' => $b]);
            $this->propertyAttributes($el, $b, $lang);
            return;
        }
        $this->graph->add($s, $p, [
            'v' => $el->textContent,
            'lang' => $lang,
            'dt' => $el->hasAttributeNS(self::RDF, 'datatype') ? $el->getAttributeNS(self::RDF, 'datatype') : null,
        ]);
    }

    private function propertyAttributes(DOMElement $el, string $s, ?string $lang): void
    {
        foreach (self::propertyAttrs($el) as $a) {
            $this->graph->add($s, $a->namespaceURI . $a->localName, ['v' => $a->value, 'lang' => $lang, 'dt' => null]);
        }
    }

    /** @return \DOMAttr[] */
    private static function propertyAttrs(DOMElement $el): array
    {
        $out = [];
        foreach ($el->attributes as $a) {
            if ($a->namespaceURI && $a->namespaceURI !== self::RDF && $a->namespaceURI !== self::XML
                && $a->namespaceURI !== 'http://www.w3.org/2000/xmlns/') {
                $out[] = $a;
            }
        }
        return $out;
    }

    private function subject(DOMElement $el): string
    {
        if ($el->hasAttributeNS(self::RDF, 'about')) {
            return $this->resolve($el, $el->getAttributeNS(self::RDF, 'about'));
        }
        if ($el->hasAttributeNS(self::RDF, 'nodeID')) {
            return '_:' . $el->getAttributeNS(self::RDF, 'nodeID');
        }
        if ($el->hasAttributeNS(self::RDF, 'ID')) {
            return '#' . $el->getAttributeNS(self::RDF, 'ID');
        }
        return '_:b' . (++$this->blank);
    }

    /** Risolve URI relativi rispetto a xml:base, se presente. */
    private function resolve(DOMElement $el, string $uri): string
    {
        if ($uri === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $uri)) {
            return $uri;
        }
        for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($n->hasAttributeNS(self::XML, 'base')) {
                $base = $n->getAttributeNS(self::XML, 'base');
                return str_starts_with($uri, '#') ? preg_replace('/#.*$/', '', $base) . $uri
                                                  : preg_replace('~[^/]*$~', '', $base) . ltrim($uri, '/');
            }
        }
        return $uri;
    }

    private function lang(DOMElement $el): ?string
    {
        return $el->hasAttributeNS(self::XML, 'lang') ? ($el->getAttributeNS(self::XML, 'lang') ?: null) : null;
    }

    /** @return DOMElement[] */
    private static function children(DOMNode $el): array
    {
        $out = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $out[] = $c;
            }
        }
        return $out;
    }
}
