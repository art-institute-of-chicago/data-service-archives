<?php

namespace App\Services;

use SimpleXMLElement;

/**
 * Extracts all common MARC21 fields into human-readable properties.
 *
 * Used by AlmaSruClient to produce a rich "normalized" block stored in
 * metadata.alma.normalized alongside the raw MARCXML.
 */
class MarcNormalizer
{
    protected MarcClassifier $classifier;

    public function __construct()
    {
        $this->classifier = new MarcClassifier();
    }

    /** Map MARC language codes to English names. */
    protected const LANGUAGE_MAP = [
        'eng' => 'English', 'fre' => 'French', 'ger' => 'German',
        'ita' => 'Italian', 'spa' => 'Spanish', 'dut' => 'Dutch',
        'fin' => 'Finnish', 'swe' => 'Swedish', 'nor' => 'Norwegian',
        'dan' => 'Danish', 'por' => 'Portuguese', 'rus' => 'Russian',
        'jpn' => 'Japanese', 'chi' => 'Chinese', 'ara' => 'Arabic',
        'lat' => 'Latin', 'gre' => 'Greek', 'heb' => 'Hebrew',
        'hun' => 'Hungarian', 'cze' => 'Czech', 'pol' => 'Polish',
        'tur' => 'Turkish', 'kor' => 'Korean',
    ];

    public function normalize(SimpleXMLElement $record): array
    {
        $collectionType = $this->classifier->collectionType($record);

        return [
            // ── Identifiers ──────────────────────────────────────────
            'identifiers' => $this->extractIdentifiers($record),

            // ── Title / Variant titles ───────────────────────────────
            'titles' => $this->extractTitles($record),

            // ── Creators & contributors ──────────────────────────────
            'authors' => $this->extractAuthors($record),

            // ── Publication / Production ─────────────────────────────
            'publication' => $this->extractPublication($record),

            // ── Physical description ─────────────────────────────────
            'physical' => $this->extractPhysical($record),

            // ── Series ───────────────────────────────────────────────
            'series' => $this->getSubfields($record, '490', 'a', 'v'),

            // ── Language ─────────────────────────────────────────────
            'language' => $this->extractLanguage($record),

            // ── Notes ────────────────────────────────────────────────
            'notes' => $this->extractNotes($record),

            // ── Subjects ─────────────────────────────────────────────
            'subjects' => $this->extractSubjects($record),

            // ── Genre / Form ─────────────────────────────────────────
            'genre_form' => $this->getAllSubfields($record, '655', 'a'),

            // ── Added entries (700 / 710 / 711) ──────────────────────
            'added_entries' => $this->extractAddedEntries($record),

            // ── Electronic access (856) ──────────────────────────────
            'electronic_access' => $this->extractElectronicAccess($record),

            // ── Holdings (852) ───────────────────────────────────────
            'holdings' => $this->extractHoldings($record),

            // ── Collection type (ContentDM + 852 $b logic) ───────────
            'collection_type' => $collectionType,

            // ── Record type ──────────────────────────────────────────
            'record_type' => $this->classifier->recordType($record, $collectionType),

            // ── Leader ───────────────────────────────────────────────
            'leader' => $this->classifier->parseLeader($record),

            // ── Cataloging source ────────────────────────────────────
            'cataloging_source' => $this->getSubfields($record, '040', 'a', 'c', 'd'),

            // ── Local / custom fields (907, 9xx) ─────────────────────
            'local' => $this->extractLocal($record),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Identifier helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractIdentifiers(SimpleXMLElement $record): array
    {
        return [
            'mms_id' => $this->controlfield($record, '001'),
            'lccn' => $this->getAllSubfields($record, '010', 'a'),
            'isbn' => $this->getAllSubfields($record, '020', 'a'),
            'issn' => $this->getAllSubfields($record, '022', 'a'),
            'other_standard' => $this->getAllSubfields($record, '024', 'a'),
            'system_numbers' => $this->getAllSubfields($record, '035', 'a'),
            'cancelled_lccn' => $this->getAllSubfields($record, '010', 'z'),
            'cancelled_isbn' => $this->getAllSubfields($record, '020', 'z'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Title helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractTitles(SimpleXMLElement $record): array
    {
        $main = $this->getFirstSubfield($record, '245', 'a');
        $subtitle = $this->getFirstSubfield($record, '245', 'b');
        $statement = $this->getFirstSubfield($record, '245', 'c');

        // Collect all 246 variant titles (full string from $a)
        $variants = [];
        foreach ($record->xpath("marc:datafield[@tag='246']") as $df) {
            $df->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $a = $df->xpath("marc:subfield[@code='a']");
            if (!empty($a)) {
                $variants[] = trim((string) $a[0]);
            }
        }

        return [
            'title' => $main ? trim($main) : null,
            'subtitle' => $subtitle ? trim($subtitle, " :/") ?: null : null,
            'statement_of_responsibility' => $statement ? trim($statement, " /,") ?: null : null,
            'variant_titles' => $variants,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Author helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractAuthors(SimpleXMLElement $record): array
    {
        $mainPersonal = $this->getFirstSubfield($record, '100', 'a');
        $mainPersonalDates = $this->getFirstSubfield($record, '100', 'd');
        $mainCorporate = $this->getFirstSubfield($record, '110', 'a');
        $mainMeeting = $this->getFirstSubfield($record, '111', 'a');

        return [
            'main_author' => $mainPersonal ? trim($mainPersonal, " ,") ?: null : null,
            'main_author_dates' => $mainPersonalDates ? trim($mainPersonalDates, " ,.") ?: null : null,
            'main_corporate_author' => $mainCorporate ? trim($mainCorporate, " ,") ?: null : null,
            'main_meeting' => $mainMeeting ? trim($mainMeeting, " ,") ?: null : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Publication helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractPublication(SimpleXMLElement $record): array
    {
        // Prefer 264 (RDA) over 260 (AACR2)
        $pub = $this->getSubfields($record, '264', 'a', 'b', 'c')
            ?: $this->getSubfields($record, '260', 'a', 'b', 'c');

        return [
            'place' => $pub['a'] ?? null,
            'publisher' => ($pub['b'] ?? null) ? trim($pub['b'], " ,") : null,
            'date' => $pub['c'] ?? null,
            'copyright_date' => $this->getFirstSubfield($record, '264', 'c', 4),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Physical helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractPhysical(SimpleXMLElement $record): array
    {
        return [
            'extent' => $this->getFirstSubfield($record, '300', 'a'),
            'illustrations' => $this->getFirstSubfield($record, '300', 'b'),
            'dimensions' => $this->getFirstSubfield($record, '300', 'c'),
            'accompanying_material' => $this->getFirstSubfield($record, '300', 'e'),
            'content_type' => $this->getFirstSubfield($record, '336', 'a'),
            'media_type' => $this->getFirstSubfield($record, '337', 'a'),
            'carrier_type' => $this->getFirstSubfield($record, '338', 'a'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Language helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractLanguage(SimpleXMLElement $record): array
    {
        $codes = $this->getAllSubfields($record, '041', 'a');
        if (empty($codes)) {
            // fall back to 008/35-37
            $cf = $record->xpath("marc:controlfield[@tag='008']");
            if (!empty($cf)) {
                $v = (string) $cf[0];
                if (strlen($v) >= 38) {
                    $c = trim(substr($v, 35, 3));
                    if ($c && $c !== '   ') {
                        $codes[] = $c;
                    }
                }
            }
        }

        $names = [];
        foreach ($codes as $c) {
            $names[] = static::LANGUAGE_MAP[$c] ?? $c;
        }

        return [
            'codes' => $codes,
            'names' => $names,
            'is_multilingual' => $this->getFirstSubfield($record, '041', 'a') !== null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Notes helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractNotes(SimpleXMLElement $record): array
    {
        return [
            'general' => $this->getFirstSubfield($record, '500', 'a'),
            'bibliography' => $this->getFirstSubfield($record, '504', 'a'),
            'contents' => $this->getFirstSubfield($record, '505', 'a'),
            'summary' => $this->getFirstSubfield($record, '520', 'a'),
            'preferred_citation' => $this->getFirstSubfield($record, '524', 'a'),
            'additional_form' => $this->getFirstSubfield($record, '530', 'a'),
            'terms_of_use' => $this->getFirstSubfield($record, '540', 'a'),
            'acquisition_source' => $this->getFirstSubfield($record, '541', 'a'),
            'related_materials' => $this->getFirstSubfield($record, '544', 'a'),
            'biographical_historical' => $this->getFirstSubfield($record, '545', 'a'),
            'language_note' => $this->getFirstSubfield($record, '546', 'a'),
            'finding_aids' => $this->getFirstSubfield($record, '555', 'a'),
            'ownership_history' => $this->getFirstSubfield($record, '561', 'a'),
            'action_note' => $this->getFirstSubfield($record, '583', 'a'),
            'exhibitions' => $this->getFirstSubfield($record, '585', 'a'),
            'local_note' => $this->getFirstSubfield($record, '590', 'a'),
            'dissertation' => $this->getFirstSubfield($record, '502', 'a'),
            'with_note' => $this->getFirstSubfield($record, '501', 'a'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Subject helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractSubjects(SimpleXMLElement $record): array
    {
        return [
            'personal' => $this->parseSubjectFields($record, '600'),
            'corporate' => $this->parseSubjectFields($record, '610'),
            'meeting' => $this->parseSubjectFields($record, '611'),
            'uniform_title' => $this->parseSubjectFields($record, '630'),
            'topical' => $this->parseSubjectFields($record, '650'),
            'geographic' => $this->parseSubjectFields($record, '651'),
            'temporal' => $this->parseSubjectFields($record, '648'),
        ];
    }

    protected function parseSubjectFields(SimpleXMLElement $record, string $tag): array
    {
        $subjects = [];
        foreach ($record->xpath("marc:datafield[@tag='{$tag}']") as $df) {
            $df->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $entry = [];
            foreach (
                ['a' => 'heading', 'd' => 'dates', 'x' => 'general_subdivision',
                'v' => 'form_subdivision', 'y' => 'chronological', 'z' => 'geographic'] as $code => $key
            ) {
                $vals = [];
                foreach ($df->xpath("marc:subfield[@code='{$code}']") as $sf) {
                    $vals[] = trim((string) $sf, " ,.");
                }
                if ($vals) {
                    $entry[$key] = count($vals) === 1 ? $vals[0] : $vals;
                }
            }
            if ($entry) {
                $subjects[] = $entry;
            }
        }
        return $subjects;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Added-entry helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractAddedEntries(SimpleXMLElement $record): array
    {
        $personal = [];
        foreach ($record->xpath("marc:datafield[@tag='700']") as $df) {
            $df->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $name = $this->sf($df, 'a');
            $dates = $this->sf($df, 'd');
            $relator = $this->sf($df, 'e');
            if ($name) {
                $entry = ['name' => trim($name, " ,.")];
                if ($dates) {
                    $entry['dates'] = trim($dates, " ,.");
                }
                if ($relator) {
                    $entry['relator'] = trim($relator, " ,.");
                }
                $personal[] = $entry;
            }
        }

        $corporate = [];
        foreach ($record->xpath("marc:datafield[@tag='710']") as $df) {
            $df->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $name = $this->sf($df, 'a');
            $sub = $this->sf($df, 'b');
            if ($name) {
                $entry = ['name' => trim($name, " ,.")];
                if ($sub) {
                    $entry['subordinate_unit'] = trim($sub, " ,.");
                }
                $corporate[] = $entry;
            }
        }

        $meeting = [];
        foreach ($record->xpath("marc:datafield[@tag='711']") as $df) {
            $df->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $name = $this->sf($df, 'a');
            if ($name) {
                $meeting[] = trim($name, " ,.");
            }
        }

        $titles = [];
        foreach (['730', '740'] as $tag) {
            $t = $this->getFirstSubfield($record, $tag, 'a');
            if ($t) {
                $titles[] = trim($t, " ,.");
            }
        }

        return [
            'personal' => $personal,
            'corporate' => $corporate,
            'meeting' => $meeting,
            'titles' => $titles,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Electronic-access helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractElectronicAccess(SimpleXMLElement $record): array
    {
        $links = [];
        foreach ($record->xpath("marc:datafield[@tag='856']") as $df) {
            $df->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $u = $this->sf($df, 'u');
            $z = $this->sf($df, 'z');
            $y = $this->sf($df, 'y');
            $_3 = $this->sf($df, '3');

            if ($u) {
                $links[] = [
                    'url' => $u,
                    'label' => $_3 ?: ($z ?: ($y ?: null)),
                ];
            }
        }
        return $links;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Holdings helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractHoldings(SimpleXMLElement $record): array
    {
        $holdings = [];
        foreach ($record->xpath("marc:datafield[@tag='852']") as $df) {
            $df->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');

            $h = [];
            foreach (
                ['a' => 'institution', 'b' => 'location', 'c' => 'shelving',
                'h' => 'call_number', 'i' => 'item_part', 't' => 'copy_number',
                'x' => 'nonpublic_note', 'z' => 'public_note'] as $code => $key
            ) {
                $v = $this->sf($df, $code);
                if ($v) {
                    $h[$key] = $v;
                }
            }
            if ($h) {
                $holdings[] = $h;
            }
        }
        return $holdings;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Local helpers
    // ─────────────────────────────────────────────────────────────────

    protected function extractLocal(SimpleXMLElement $record): array
    {
        return [
            'call_number' => $this->getFirstSubfield($record, '090', 'a')
                ?? $this->getFirstSubfield($record, '050', 'a'),
            'call_number_suffix' => $this->getFirstSubfield($record, '090', 'b')
                ?? $this->getFirstSubfield($record, '050', 'b'),
            'dewey' => $this->getFirstSubfield($record, '082', 'a'),
            'date1_date2' => $this->extractDate1Date2($record),
            'fixed_008' => $this->controlfield($record, '008'),
        ];
    }

    protected function extractDate1Date2(SimpleXMLElement $record): ?array
    {
        $cf = $record->xpath("marc:controlfield[@tag='008']");
        if (empty($cf)) {
            return null;
        }
        $v = (string) $cf[0];
        if (strlen($v) < 15) {
            return null;
        }
        $d1 = trim(substr($v, 7, 4));
        $d2 = trim(substr($v, 11, 4));
        $dates = [];
        if ($d1 && $d1 !== 'uuuu') {
            $dates['date1'] = $d1;
        }
        if ($d2 && $d2 !== 'uuuu' && $d2 !== '    ') {
            $dates['date2'] = $d2;
        }
        return $dates ?: null;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Low-level extraction utilities
    // ─────────────────────────────────────────────────────────────────

    /** Single subfield value from a datafield, or null. */
    protected function sf(SimpleXMLElement $datafield, string $code): ?string
    {
        $s = $datafield->xpath("marc:subfield[@code='{$code}']");
        return !empty($s) ? trim((string) $s[0]) : null;
    }

    /** First occurrence of tag → code across the record, or null. */
    protected function getFirstSubfield(SimpleXMLElement $record, string $tag, string $code, int $occurrence = 1): ?string
    {
        $nodes = $record->xpath("marc:datafield[@tag='{$tag}']/marc:subfield[@code='{$code}']");
        $idx = $occurrence - 1;
        return isset($nodes[$idx]) ? trim((string) $nodes[$idx]) : null;
    }

    /** All values for tag → code. */
    protected function getAllSubfields(SimpleXMLElement $record, string $tag, string $code): array
    {
        $vals = [];
        foreach ($record->xpath("marc:datafield[@tag='{$tag}']/marc:subfield[@code='{$code}']") as $sf) {
            $vals[] = trim((string) $sf);
        }
        return $vals;
    }

    /** Multiple subfield codes from first occurrence of a tag, returns assoc array. */
    protected function getSubfields(SimpleXMLElement $record, string $tag, string ...$codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $v = $this->getFirstSubfield($record, $tag, $code);
            if ($v !== null) {
                $out[$code] = $v;
            }
        }
        return $out;
    }

    /** Control field value, or null. */
    protected function controlfield(SimpleXMLElement $record, string $tag): ?string
    {
        $cf = $record->xpath("marc:controlfield[@tag='{$tag}']");
        if (empty($cf)) {
            return null;
        }
        $v = trim((string) $cf[0]);
        return $v !== '' ? $v : null;
    }
}
