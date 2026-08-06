<?php

namespace WiserWebSolutions\PDEClient\Tests\Assessment\Parsing;

use WiserWebSolutions\PDEClient\Assessment\Parsing\AssessmentWorkbookParser;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class AssessmentWorkbookParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    private const HEADER = [
        'AUN', 'County', 'District Name', 'Subject', 'Group', 'Grade',
        'Number Scored', 'Percent Advanced', 'Percent Proficient',
        'Percent Basic', 'Percent Below Basic', 'Percent Proficient and above',
    ];

    public function test_parses_a_row_per_subject_group_grade(): void
    {
        $path = $this->xlsxFixture([
            'PSSA District Data' => [
                ['2024-2025 PSSA Results'],
                self::HEADER,
                [124157203, 'Chester', 'Phoenixville Area SD', 'Math', 'All Students', '3', 50, 10, 40, 30, 20, 50],
                [124157203, 'Chester', 'Phoenixville Area SD', 'Math', 'All Students', 'Total', 200, 12, 38, 28, 22, 50],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame([
            '124157203' => ['name' => 'Phoenixville Area SD', 'county' => 'Chester', 'lea_type' => null],
        ], $table->districts);

        $this->assertCount(2, $table->rows['124157203']);

        $first = $table->rows['124157203'][0];
        $this->assertSame('Math', $first['subject']);
        $this->assertSame('All Students', $first['group']);
        $this->assertSame('3', $first['grade']);
        $this->assertSame(50.0, $first['scored']);
        $this->assertSame(10.0, $first['advanced']);
        $this->assertSame(40.0, $first['proficient']);
        $this->assertSame(30.0, $first['basic']);
        $this->assertSame(20.0, $first['below_basic']);
        $this->assertSame(50.0, $first['proficient_or_above']);
    }

    public function test_normalizes_the_legacy_district_total_grade_label_to_total(): void
    {
        $path = $this->xlsxFixture([
            'Keystone District Data' => [
                self::HEADER,
                [124157203, 'Chester', 'Phoenixville Area SD', 'Algebra I', 'All Students', 'District Total', 200, 10, 40, 30, 20, 50],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame('Total', $table->rows['124157203'][0]['grade']);
    }

    public function test_suppressed_percentages_come_through_as_null(): void
    {
        $path = $this->xlsxFixture([
            'PSSA District Data' => [
                self::HEADER,
                [124157203, 'Chester', 'Phoenixville Area SD', 'Math', 'American Indian', '3', '<11', '*', '*', '*', '*', '*'],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $row = $table->rows['124157203'][0];
        $this->assertNull($row['scored']);
        $this->assertNull($row['advanced']);
        $this->assertNull($row['proficient']);
    }

    public function test_skips_rows_with_a_malformed_aun(): void
    {
        $path = $this->xlsxFixture([
            'PSSA District Data' => [
                self::HEADER,
                ['State Total', 'Chester', 'Statewide', 'Math', 'All Students', '3', 5000, 10, 40, 30, 20, 50],
                [124157203, 'Chester', 'Phoenixville Area SD', 'Math', 'All Students', '3', 50, 10, 40, 30, 20, 50],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertCount(1, $table->districts);
        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_locates_the_header_row_under_a_title_block_on_any_sheet(): void
    {
        $path = $this->xlsxFixture([
            'Cover' => [
                ['This sheet has no AUN/Subject columns at all'],
            ],
            'PSSA District Data' => [
                ['2024-2025 PSSA Results - District Level'],
                ['Prepared by PDE'],
                self::HEADER,
                [124157203, 'Chester', 'Phoenixville Area SD', 'Math', 'All Students', '3', 50, 10, 40, 30, 20, 50],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_throws_when_a_required_column_is_missing(): void
    {
        $header = self::HEADER;
        unset($header[array_search('Percent Advanced', $header, true)]);
        $header = array_values($header);

        $path = $this->xlsxFixture([
            'PSSA District Data' => [
                $header,
                [124157203, 'Chester', 'Phoenixville Area SD', 'Math', 'All Students', '3', 50, 40, 30, 20, 50],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('missing expected column');

        $this->parser()->parse($path);
    }

    public function test_throws_when_no_sheet_has_the_expected_header(): void
    {
        $path = $this->xlsxFixture([
            'Summary' => [
                ['Something', 'Else'],
                ['a', 'b'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('AUN/Subject header row');

        $this->parser()->parse($path);
    }

    private function parser(): AssessmentWorkbookParser
    {
        return new AssessmentWorkbookParser(new SpreadsheetReader());
    }
}
