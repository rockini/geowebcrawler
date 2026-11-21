<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use DOMElement;
use DOMXPath;

/** Legge un record CSW in formato Dublin Core (csw:Record), supportato da tutti i cataloghi CSW. */
final class DublinCoreParser
{
    public function __construct(private EndpointClassifier $classifier = new EndpointClassifier()) {}

    public function parse(DOMXPath $xp, DOMElement $rec): ?Record
    {
        $title = Xml::text($xp, 'dc:title', $rec);
        if ($title === null) {
            return null;
        }
        $id = Xml::text($xp, 'dc:identifier', $rec);
        $r = new Record($id ?? md5($title), $title);
        $r->externalId = $id;
        $r->metadataStandard = 'Dublin Core';

        $type = strtolower((string) Xml::text($xp, 'dc:type', $rec));
        $r->resourceType = match ($type) {
            'dataset', '' => 'dataset',
            'series' => 'series',
            'service' => 'service',
            default => 'other',
        };
        $r->abstract      = Xml::text($xp, 'dct:abstract | dc:description', $rec);
        $r->keywords      = Xml::texts($xp, 'dc:subject', $rec);
        $r->language      = Xml::text($xp, 'dc:language', $rec);
        $r->datePublished = Xml::date(Xml::text($xp, 'dc:date | dct:issued', $rec));
        $r->dateRevised   = Xml::date(Xml::text($xp, 'dct:modified', $rec));
        $rights           = Xml::texts($xp, 'dc:rights | dct:license | dct:accessRights', $rec);
        $r->licenseText   = $rights ? implode(' | ', $rights) : null;

        foreach (Xml::texts($xp, 'dc:creator', $rec) as $n)     { $r->addParty($n, 'author'); }
        foreach (Xml::texts($xp, 'dc:publisher', $rec) as $n)   { $r->addParty($n, 'publisher'); }
        foreach (Xml::texts($xp, 'dc:contributor', $rec) as $n) { $r->addParty($n, 'other'); }

        // ows:BoundingBox. Con crs "urn:ogc:def:crs:EPSG::4326" l'ordine e' latitudine, longitudine.
        foreach ($xp->query('ows:BoundingBox | ows:WGS84BoundingBox', $rec) as $bb) {
            $lc = preg_split('/\s+/', (string) Xml::text($xp, 'ows:LowerCorner', $bb));
            $uc = preg_split('/\s+/', (string) Xml::text($xp, 'ows:UpperCorner', $bb));
            if (count($lc) !== 2 || count($uc) !== 2) {
                continue;
            }
            $crs = (string) $bb->getAttribute('crs');
            $latFirst = $bb->localName === 'BoundingBox' && preg_match('~urn:ogc:def:crs:EPSG:[^:]*:4326~i', $crs);
            [$w, $s, $e, $n] = $latFirst
                ? [Xml::num($lc[1]), Xml::num($lc[0]), Xml::num($uc[1]), Xml::num($uc[0])]
                : [Xml::num($lc[0]), Xml::num($lc[1]), Xml::num($uc[0]), Xml::num($uc[1])];
            if ($crs === '' || $latFirst || preg_match('~(4326|crs84)$~i', $crs)) {
                $r->bbox = Xml::bbox($w, $s, $e, $n);
                if ($r->bbox !== null) {
                    break;
                }
            }
        }

        foreach ($xp->query('dc:URI | dct:references', $rec) as $u) {
            /** @var DOMElement $u */
            $r->addEndpoint($this->classifier->classify(
                trim($u->textContent),
                $u->getAttribute('protocol') ?: $u->getAttribute('scheme') ?: null,
                $u->getAttribute('description') ?: $u->getAttribute('name') ?: null,
            ));
        }

        $r->rawMetadata = $rec->ownerDocument->saveXML($rec);
        return $r;
    }
}
