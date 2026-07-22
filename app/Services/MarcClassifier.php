<?php

namespace App\Services;

use SimpleXMLElement;

/**
 * Single source of truth for MARC-derived classification (collection_type,
 * record_type, leader parsing). Shared by AlmaSruClient (flat archive fields)
 * and MarcNormalizer (nested metadata.alma.normalized block) so the two
 * outputs can never disagree with each other.
 */
class MarcClassifier
{
    public function collectionType(SimpleXMLElement $record): ?string
    {
        // Priority 1: ContentDM URL in 856 $u → archives (overrides everything)
        foreach ($record->xpath("marc:datafield[@tag='856']/marc:subfield[@code='u']") as $u) {
            $url = (string) $u;
            if (stripos($url, 'contentdm') !== false || stripos($url, 'cdm') !== false) {
                return 'archives';
            }
        }

        // Priority 2-3: 852 $b logic
        $locations = $record->xpath("marc:datafield[@tag='852']/marc:subfield[@code='b']");
        if (!empty($locations)) {
            foreach ($locations as $loc) {
                $code = strtolower(trim((string) $loc));
                if (in_array($code, $this->archivesLocationCodes(), true)) {
                    return 'archives';
                }
            }
            return 'library';
        }

        // Priority 4: Leader/06 fallback when no 852
        $leader = $this->parseLeader($record);
        if ($leader && ($leader['type_of_record'] ?? null) === 'p') {
            return 'archives';
        }

        return 'library';
    }

    public function recordType(SimpleXMLElement $record, ?string $collectionType): ?string
    {
        if ($collectionType === 'archives') {
            return $this->archivesRecordType($record);
        }

        if ($collectionType === 'library') {
            return $this->libraryRecordType($record);
        }

        return null;
    }

    public function archivesRecordType(SimpleXMLElement $record): string
    {
        $genreForm = $this->subfield($record, '655', 'a');
        $physicalB = $this->subfield($record, '300', 'b');
        $contentType = $this->subfield($record, '336', 'a');
        $leader = $this->parseLeader($record);
        $leader06 = $leader['type_of_record'] ?? null;

        $combined = strtolower(implode(' ', array_filter([$genreForm, $physicalB, $contentType])));

        if (str_contains($combined, 'exhibition') || str_contains($combined, 'catalog')) {
            return 'exhibition_catalog';
        }
        if (str_contains($combined, 'correspondence') || str_contains($combined, 'letters')) {
            return 'correspondence';
        }
        if (str_contains($combined, 'artist file')) {
            return 'artist_files';
        }
        if (str_contains($combined, 'photograph') || $contentType === 'still image') {
            return 'photographs';
        }
        if (in_array($leader06, ['t', 'd', 'f'], true)) {
            return 'manuscript';
        }
        if (str_contains($combined, 'scrapbook')) {
            return 'scrapbook';
        }
        if (str_contains($combined, 'pamphlet') || str_contains($combined, 'brochure')) {
            return 'printed_material';
        }
        if (str_contains($combined, 'sketchbook') || str_contains($combined, 'drawing')) {
            return 'sketchbook';
        }
        if (str_contains($combined, 'poster')) {
            return 'posters';
        }

        return 'ephemera';
    }

    public function libraryRecordType(SimpleXMLElement $record): string
    {
        $leader = $this->parseLeader($record);
        $leader06 = $leader['type_of_record'] ?? null;
        $leader07 = $leader['bibliographic_level'] ?? null;

        if ($leader07 === 's') {
            return 'journal';
        }
        if ($this->subfield($record, '502', 'a') !== null) {
            return 'thesis';
        }
        // Conference proceedings: 111 present or 655 "Conference"
        $meeting111 = $record->xpath("marc:datafield[@tag='111']");
        $genreForm = $this->subfield($record, '655', 'a');
        if (!empty($meeting111) || ($genreForm && stripos($genreForm, 'conference') !== false)) {
            return 'conference_proceedings';
        }
        // Reference: 655 "Bibliography" or "Dictionaries"
        if ($genreForm && (stripos($genreForm, 'bibliography') !== false || stripos($genreForm, 'dictionaries') !== false)) {
            return 'reference';
        }
        // Government document: 086 present or 110 with gov body
        $gov086 = $record->xpath("marc:datafield[@tag='086']");
        if (!empty($gov086)) {
            return 'government_document';
        }
        $corp110 = $this->subfield($record, '110', 'a');
        if ($corp110 && preg_match('/(United States|Dept\.?|Department|Ministry|Gov(ernment)?)/i', $corp110)) {
            return 'government_document';
        }
        // Microform: 007/00 = 'h'
        $field007 = $record->xpath("marc:controlfield[@tag='007']");
        if (!empty($field007)) {
            $v007 = (string) $field007[0];
            if (strlen($v007) > 0 && $v007[0] === 'h') {
                return 'microform';
            }
        }
        // Map: Leader/06 = 'e' or 'f'
        if (in_array($leader06, ['e', 'f'], true)) {
            return 'map';
        }
        // Music score: Leader/06 = 'c' or 'd'
        if (in_array($leader06, ['c', 'd'], true)) {
            return 'music_score';
        }
        // Sound recording: Leader/06 = 'i' or 'j'
        if (in_array($leader06, ['i', 'j'], true)) {
            return 'sound_recording';
        }
        // Video: Leader/06 = 'g'
        if ($leader06 === 'g') {
            return 'video';
        }
        // Mixed material: Leader/06 = 'p'
        if ($leader06 === 'p') {
            return 'mixed_material';
        }
        // Kit: Leader/06 = 'o'
        if ($leader06 === 'o') {
            return 'kit';
        }
        // Book: Leader/06 = 'a', Leader/07 = 'm'
        if ($leader06 === 'a' && $leader07 === 'm') {
            return 'book';
        }

        return 'other';
    }

    public function parseLeader(SimpleXMLElement $record): ?array
    {
        $leaderField = $record->xpath('marc:leader');
        if (empty($leaderField)) {
            return null;
        }
        $leader = (string) $leaderField[0];
        if (strlen($leader) < 24) {
            return null;
        }

        return [
            'type_of_record' => $leader[6] !== ' ' ? $leader[6] : null,
            'bibliographic_level' => $leader[7] !== ' ' ? $leader[7] : null,
            'encoding_level' => $leader[17] !== ' ' ? $leader[17] : null,
            'descriptive_cataloging' => $leader[18] !== ' ' ? $leader[18] : null,
        ];
    }

    public function archivesLocationCodes(): array
    {
        return [
            'o', 'p', 'ar', 'sa', 'ia', 'archmicro',
            'pd', 'pho', 'pc', 'sp', 'speccirc',
            'spfl', 'spk', 'rspk', 'rsp', 'spkff',
            'rskff', 'sff', 'spf', 'rspf', 'sf',
            'spfx', 'rspfx', 'sfx',
        ];
    }

    /** First occurrence of tag → code across the record, or null. */
    protected function subfield(SimpleXMLElement $record, string $tag, string $code): ?string
    {
        $nodes = $record->xpath("marc:datafield[@tag='{$tag}']/marc:subfield[@code='{$code}']");
        return !empty($nodes) ? trim((string) $nodes[0]) : null;
    }
}
