<?php
declare(strict_types=1);

namespace GeoWebCrawler\Harvest;

use DOMElement;
use DOMXPath;

/** Legge un record di metadati ISO 19139 (gmd:MD_Metadata), il formato usato da RNDT e INSPIRE. */
final class Iso19139Parser
{
    private const ROLES = [
        'originator' => 'author', 'author' => 'author', 'principalInvestigator' => 'author',
        'publisher' => 'publisher', 'owner' => 'owner', 'pointOfContact' => 'pointOfContact',
        'distributor' => 'distributor', 'resourceProvider' => 'distributor', 'custodian' => 'custodian',
    ];

    public function __construct(private EndpointClassifier $classifier = new EndpointClassifier()) {}

    public function parse(DOMXPath $xp, DOMElement $md): ?Record
    {
        // testo da CharacterString o da gmx:Anchor (usato per i vocabolari INSPIRE)
        $cs = fn(string $p): string => "$p/gco:CharacterString | $p/gmx:Anchor";

        $id    = Xml::text($xp, $cs('gmd:fileIdentifier'), $md);
        $ident = $xp->query('gmd:identificationInfo/*[1]', $md)->item(0);
        if (!$ident instanceof DOMElement) {
            return null;
        }
        $title = Xml::text($xp, $cs('gmd:citation/gmd:CI_Citation/gmd:title'), $ident);
        if ($title === null) {
            return null;
        }

        $r = new Record($id ?? md5($title), $title);
        $r->externalId = $id;
        $r->metadataStandard = 'ISO 19139';

        $scope = Xml::text($xp, 'gmd:hierarchyLevel/gmd:MD_ScopeCode/@codeListValue', $md);
        $r->resourceType = match ($scope) {
            'series' => 'series',
            'service' => 'service',
            'dataset', null => $ident->localName === 'SV_ServiceIdentification' ? 'service' : 'dataset',
            default => 'other',
        };

        $r->abstract = Xml::text($xp, $cs('gmd:abstract'), $ident);
        $r->language = Xml::text($xp, 'gmd:language/gmd:LanguageCode/@codeListValue | ' . $cs('gmd:language'), $md);
        $r->metadataDate = Xml::text($xp, 'gmd:dateStamp/gco:DateTime | gmd:dateStamp/gco:Date', $md);

        foreach ($xp->query('gmd:citation/gmd:CI_Citation/gmd:date/gmd:CI_Date', $ident) as $d) {
            $value = Xml::date(Xml::text($xp, 'gmd:date/gco:Date | gmd:date/gco:DateTime', $d));
            $type  = Xml::text($xp, 'gmd:dateType/gmd:CI_DateTypeCode/@codeListValue', $d);
            match ($type) {
                'creation'    => $r->dateCreated   ??= $value,
                'publication' => $r->datePublished ??= $value,
                'revision'    => $r->dateRevised   = max($r->dateRevised ?? '', $value ?? '') ?: null,
                default       => null,
            };
        }

        $r->keywords = Xml::texts($xp, 'gmd:descriptiveKeywords/gmd:MD_Keywords/gmd:keyword/gco:CharacterString'
                                     . ' | gmd:descriptiveKeywords/gmd:MD_Keywords/gmd:keyword/gmx:Anchor', $ident);
        $r->topicCategories = Xml::texts($xp, 'gmd:topicCategory/gmd:MD_TopicCategoryCode', $ident);
        $r->updateFrequency = Xml::text($xp, 'gmd:resourceMaintenance//gmd:MD_MaintenanceFrequencyCode/@codeListValue', $ident);

        // enti responsabili della risorsa
        foreach ($xp->query('gmd:pointOfContact/gmd:CI_ResponsibleParty | gmd:citation/gmd:CI_Citation/gmd:citedResponsibleParty/gmd:CI_ResponsibleParty', $ident) as $p) {
            $name = Xml::text($xp, $cs('gmd:organisationName'), $p) ?? Xml::text($xp, $cs('gmd:individualName'), $p);
            $role = Xml::text($xp, 'gmd:role/gmd:CI_RoleCode/@codeListValue', $p);
            $r->addParty((string) $name, self::ROLES[$role] ?? 'other',
                Xml::text($xp, './/gmd:electronicMailAddress/gco:CharacterString', $p),
                Xml::text($xp, './/gmd:onlineResource//gmd:URL', $p));
        }

        // licenza e vincoli: il testo dei vincoli legali contiene di solito la licenza
        $legal = Xml::texts($xp, 'gmd:resourceConstraints/gmd:MD_LegalConstraints/gmd:otherConstraints/gco:CharacterString'
                              . ' | gmd:resourceConstraints/gmd:MD_LegalConstraints/gmd:otherConstraints/gmx:Anchor'
                              . ' | gmd:resourceConstraints/*/gmd:useLimitation/gco:CharacterString', $ident);
        $r->licenseText = $legal ? implode(' | ', $legal) : null;
        $access = Xml::texts($xp, 'gmd:resourceConstraints/gmd:MD_LegalConstraints/gmd:accessConstraints/gmd:MD_RestrictionCode/@codeListValue', $ident);
        $r->accessConstraints = $access ? implode(', ', $access) : null;

        // sistemi di riferimento
        $r->crs = Xml::epsg(Xml::texts($xp, 'gmd:referenceSystemInfo//gmd:RS_Identifier/gmd:code/gco:CharacterString'
                                          . ' | gmd:referenceSystemInfo//gmd:RS_Identifier/gmd:code/gmx:Anchor'
                                          . ' | gmd:referenceSystemInfo//gmd:RS_Identifier/gmd:code/gmx:Anchor/@xlink:href', $md));

        // estensione geografica: unione di tutti i riquadri presenti
        $w = $s = $e = $n = null;
        foreach ($xp->query('.//gmd:EX_GeographicBoundingBox', $ident) as $bb) {
            $bw = Xml::num(Xml::text($xp, 'gmd:westBoundLongitude/gco:Decimal', $bb));
            $be = Xml::num(Xml::text($xp, 'gmd:eastBoundLongitude/gco:Decimal', $bb));
            $bs = Xml::num(Xml::text($xp, 'gmd:southBoundLatitude/gco:Decimal', $bb));
            $bn = Xml::num(Xml::text($xp, 'gmd:northBoundLatitude/gco:Decimal', $bb));
            if (Xml::bbox($bw, $bs, $be, $bn) !== null) {
                $w = $w === null ? $bw : min($w, $bw);
                $s = $s === null ? $bs : min($s, $bs);
                $e = $e === null ? $be : max($e, $be);
                $n = $n === null ? $bn : max($n, $bn);
            }
        }
        $r->bbox = Xml::bbox($w, $s, $e, $n);

        // formati dichiarati, usati quando il link non ha un'estensione riconoscibile
        $declaredFormat = $this->formatCode(Xml::text($xp, 'gmd:distributionInfo//gmd:MD_Format/gmd:name/gco:CharacterString'
                                                        . ' | gmd:distributionInfo//gmd:MD_Format/gmd:name/gmx:Anchor', $md));

        // punti di accesso
        foreach ($xp->query('gmd:distributionInfo//gmd:transferOptions//gmd:onLine/gmd:CI_OnlineResource', $md) as $ol) {
            $url = Xml::text($xp, 'gmd:linkage/gmd:URL', $ol);
            if ($url === null) {
                continue;
            }
            $protocol = Xml::text($xp, $cs('gmd:protocol'), $ol);
            $name     = Xml::text($xp, $cs('gmd:name'), $ol);
            $desc     = Xml::text($xp, $cs('gmd:description'), $ol);
            $function = Xml::text($xp, 'gmd:function/gmd:CI_OnLineFunctionCode/@codeListValue', $ol);
            if ($function === 'download' && $protocol === null) {
                $protocol = 'download';
            }
            $ep = $this->classifier->classify($url, $protocol, $desc ?? $name);
            if ($ep === null) {
                continue;
            }
            // per i servizi OGC il campo "name" contiene di solito il nome del layer
            if ($ep->layerName === null && $name !== null && $ep->type !== 'download' && $ep->type !== 'webpage'
                && !preg_match('~\s~', $name)) {
                $ep->layerName = $name;
            }
            if ($ep->type === 'download' && $ep->format === null) {
                $ep->format = $declaredFormat;
            }
            $r->addEndpoint($ep);
        }

        $r->rawMetadata = $md->ownerDocument->saveXML($md);
        return $r;
    }

    /** Converte un nome di formato libero ("ESRI Shapefile", "GeoTIFF") nel codice di gwc.formats. */
    private function formatCode(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $n = strtolower($name);
        return match (true) {
            str_contains($n, 'shape') || $n === 'shp' => 'shp',
            str_contains($n, 'geojson')               => 'geojson',
            str_contains($n, 'geopackage') || $n === 'gpkg' => 'gpkg',
            str_contains($n, 'gml')                   => 'gml',
            str_contains($n, 'kmz')                   => 'kmz',
            str_contains($n, 'kml')                   => 'kml',
            str_contains($n, 'tif')                   => 'geotiff',
            str_contains($n, 'dxf') || str_contains($n, 'dwg') => 'dxf',
            str_contains($n, 'ascii') || preg_match('/\basc\b/', $n) === 1 => 'ascii_grid',
            str_contains($n, 'csv')                   => 'csv',
            preg_match('/\bla[sz]\b/', $n) === 1      => 'las',
            str_contains($n, 'netcdf')                => 'netcdf',
            str_contains($n, 'zip')                   => 'zip',
            default                                   => null,
        };
    }
}
