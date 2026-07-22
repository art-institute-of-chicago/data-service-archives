<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\AlmaSruClient;
use PHPUnit\Framework\Attributes\DataProvider;
use SimpleXMLElement;

class AlmaSruClientRecordTypeTest extends TestCase
{
    protected AlmaSruClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new AlmaSruClient();
    }

    // ────────────────────────────────
    //  Collection type
    // ────────────────────────────────

    public function test_collection_type_contentdm_url_overrides_852(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cam a2200421 a 4500',
            '852' => [['b' => 'sk']],
            '856' => [['u' => 'https://cdm16735.contentdm.oclc.org/digital/api/collection/x/id/1/download']],
        ]);

        $result = $this->invokeExtractCollectionType($xml, [['url' => 'https://cdm16735.contentdm.oclc.org/digital/api/collection/x/id/1/download']]);
        $this->assertSame('archives', $result);
    }

    #[DataProvider('archivesLocationCodeProvider')]
    public function test_collection_type_archives_location_code_returns_archives(string $code): void
    {
        $xml = $this->makeRecord([
            '852' => [['b' => $code]],
        ]);

        $result = $this->invokeExtractCollectionType($xml, []);
        $this->assertSame('archives', $result);
    }

    public static function archivesLocationCodeProvider(): array
    {
        return [
            'o (Archives)' => ['o'],
            'p (Artist Files)' => ['p'],
            'ar (R&B Archives Stack B)' => ['ar'],
            'spk (Special K)' => ['spk'],
        ];
    }

    public function test_collection_type_852_sk_returns_library(): void
    {
        $xml = $this->makeRecord([
            '852' => [['b' => 'sk']],
        ]);

        $result = $this->invokeExtractCollectionType($xml, []);
        $this->assertSame('library', $result);
    }

    public function test_collection_type_no_852_returns_library(): void
    {
        $xml = $this->makeRecord([]);

        $result = $this->invokeExtractCollectionType($xml, []);
        $this->assertSame('library', $result);
    }

    // ────────────────────────────────
    //  Record type — archives
    // ────────────────────────────────

    public function test_record_type_exhibition_catalog(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cam a2200421 a 4500',
            '655' => [['a' => 'Exhibition catalogs']],
        ]);

        $result = $this->invokeExtractRecordType($xml, 'archives');
        $this->assertSame('exhibition_catalog', $result);
    }

    public function test_record_type_correspondence(): void
    {
        $xml = $this->makeRecord([
            '655' => [['a' => 'Personal correspondence']],
        ]);

        $result = $this->invokeExtractRecordType($xml, 'archives');
        $this->assertSame('correspondence', $result);
    }

    public function test_record_type_photographs_from_336(): void
    {
        $xml = $this->makeRecord([
            '336' => [['a' => 'still image']],
        ]);

        $result = $this->invokeExtractRecordType($xml, 'archives');
        $this->assertSame('photographs', $result);
    }

    public function test_record_type_manuscript_from_leader_t(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946ctm a2200421 a 4500',
        ]);

        $result = $this->invokeExtractRecordType($xml, 'archives');
        $this->assertSame('manuscript', $result);
    }

    public function test_record_type_scrapbook(): void
    {
        $xml = $this->makeRecord([
            '655' => [['a' => 'Scrapbooks']],
        ]);

        $result = $this->invokeExtractRecordType($xml, 'archives');
        $this->assertSame('scrapbook', $result);
    }

    public function test_record_type_archives_fallback_ephemera(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cam a2200421 a 4500',
        ]);

        $result = $this->invokeExtractRecordType($xml, 'archives');
        $this->assertSame('ephemera', $result);
    }

    // ────────────────────────────────
    //  Record type — library
    // ────────────────────────────────

    public function test_record_type_book(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cam a2200421 a 4500',
        ]);

        $result = $this->invokeExtractRecordType($xml, 'library');
        $this->assertSame('book', $result);
    }

    public function test_record_type_journal(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cas a2200421 a 4500',
        ]);

        $result = $this->invokeExtractRecordType($xml, 'library');
        $this->assertSame('journal', $result);
    }

    public function test_record_type_thesis(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cam a2200421 a 4500',
            '502' => [['a' => 'Thesis (Ph.D.)--University of Chicago, 2020']],
        ]);

        $result = $this->invokeExtractRecordType($xml, 'library');
        $this->assertSame('thesis', $result);
    }

    public function test_record_type_video(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cgm a2200421 a 4500',
        ]);

        $result = $this->invokeExtractRecordType($xml, 'library');
        $this->assertSame('video', $result);
    }

    public function test_record_type_microform(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cam a2200421 a 4500',
            '007' => 'hd afa---baca',
        ]);

        $result = $this->invokeExtractRecordType($xml, 'library');
        $this->assertSame('microform', $result);
    }

    public function test_record_type_artist_files_when_leader_p_falls_back_to_archives(): void
    {
        // Leader/06='p' + no 852 → archives (leader fallback)
        // 655 "Artist files" → artist_files (archivesRecordType, not libraryRecordType's "mixed_material")
        $xml = $this->makeRecord([
            'leader' => '01946cpm a2200421 a 4500',
            '655' => [['a' => 'Artist files']],
        ]);

        $collType = $this->invokeExtractCollectionType($xml, []);
        $this->assertSame('archives', $collType);
        $recType = $this->invokeExtractRecordType($xml, 'archives');
        $this->assertSame('artist_files', $recType);
    }

    public function test_collection_type_no_852_leader_p_is_archives(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cpm a2200421 a 4500',
        ]);

        $result = $this->invokeExtractCollectionType($xml, []);
        $this->assertSame('archives', $result);
    }

    public function test_collection_type_no_852_leader_a_is_library(): void
    {
        $xml = $this->makeRecord([
            'leader' => '01946cam a2200421 a 4500',
        ]);

        $result = $this->invokeExtractCollectionType($xml, []);
        $this->assertSame('library', $result);
    }

    // ────────────────────────────────
    //  Leader parsing
    // ────────────────────────────────

    public function test_parse_leader(): void
    {
        $xml = $this->makeRecord(['leader' => '01946cam a2200421 a 4500']);

        $result = $this->invokeParseLeader($xml);
        $this->assertSame('a', $result['type_of_record']);
        $this->assertSame('m', $result['bibliographic_level']);
        $this->assertNull($result['encoding_level']);
        $this->assertSame('a', $result['descriptive_cataloging']);
    }

    public function test_parse_leader_manuscript(): void
    {
        $xml = $this->makeRecord(['leader' => '01946ctm a2200421 a 4500']);

        $result = $this->invokeParseLeader($xml);
        $this->assertSame('t', $result['type_of_record']);
    }

    public function test_parse_leader_missing(): void
    {
        $xml = $this->makeRecord([]);

        $result = $this->invokeParseLeader($xml);
        $this->assertNull($result);
    }

    // ────────────────────────────────
    //  Helpers
    // ────────────────────────────────

    protected function makeRecord(array $fields): SimpleXMLElement
    {
        $xml = '<record xmlns="http://www.loc.gov/MARC21/slim">';

        if (isset($fields['leader'])) {
            $xml .= '<leader>' . $fields['leader'] . '</leader>';
        }

        if (isset($fields['001'])) {
            $xml .= '<controlfield tag="001">' . $fields['001'] . '</controlfield>';
        }

        foreach (['007'] as $tag) {
            if (isset($fields[$tag])) {
                $xml .= '<controlfield tag="' . $tag . '">' . $fields[$tag] . '</controlfield>';
            }
        }

        foreach (['655', '300', '336', '337', '338', '502', '852', '856', '111', '086', '110'] as $tag) {
            if (isset($fields[$tag])) {
                foreach ($fields[$tag] as $df) {
                    $xml .= '<datafield tag="' . $tag . '">';
                    foreach ($df as $code => $value) {
                        $xml .= '<subfield code="' . $code . '">' . $value . '</subfield>';
                    }
                    $xml .= '</datafield>';
                }
            }
        }

        $xml .= '</record>';

        $record = new SimpleXMLElement($xml);
        $record->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');

        return $record;
    }

    protected function makeSruResponse(array $recordFields): SimpleXMLElement
    {
        $recordXml = '';
        if (isset($recordFields['leader'])) {
            $recordXml .= '<leader>' . $recordFields['leader'] . '</leader>';
        }
        if (isset($recordFields['001'])) {
            $recordXml .= '<controlfield tag="001">' . $recordFields['001'] . '</controlfield>';
        }
        foreach (['852', '856', '655', '300', '336', '111', '502', '086', '110', '007'] as $tag) {
            if (isset($recordFields[$tag])) {
                foreach ($recordFields[$tag] as $df) {
                    $recordXml .= '<datafield tag="' . $tag . '">';
                    foreach ($df as $code => $value) {
                        $recordXml .= '<subfield code="' . $code . '">' . $value . '</subfield>';
                    }
                    $recordXml .= '</datafield>';
                }
            }
        }

        $xml = '<?xml version="1.0"?>'
            . '<searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">'
            . '<version>1.2</version>'
            . '<numberOfRecords>1</numberOfRecords>'
            . '<records>'
            . '<record>'
            . '<recordSchema>marcxml</recordSchema>'
            . '<recordData>'
            . '<record xmlns="http://www.loc.gov/MARC21/slim">'
            . $recordXml
            . '</record>'
            . '</recordData>'
            . '</record>'
            . '</records>'
            . '</searchRetrieveResponse>';

        return new SimpleXMLElement($xml);
    }

    /** Invoke private extractCollectionType via reflection */
    protected function invokeExtractCollectionType(SimpleXMLElement $record, array $fileUrls): ?string
    {
        $ref = new \ReflectionMethod(AlmaSruClient::class, 'extractCollectionType');
        return $ref->invoke($this->client, $record, $fileUrls);
    }

    /** Invoke private extractRecordType via reflection */
    protected function invokeExtractRecordType(SimpleXMLElement $record, ?string $collectionType): ?string
    {
        $ref = new \ReflectionMethod(AlmaSruClient::class, 'extractRecordType');
        return $ref->invoke($this->client, $record, $collectionType);
    }

    /** Invoke private parseLeader via reflection */
    protected function invokeParseLeader(SimpleXMLElement $record): ?array
    {
        $ref = new \ReflectionMethod(AlmaSruClient::class, 'parseLeader');
        return $ref->invoke($this->client, $record);
    }
}
