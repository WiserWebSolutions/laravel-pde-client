<?php

namespace WiserWebSolutions\PDEClient\Tests\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Enrollment\Parsing\EnglishLearnersParser;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class EnglishLearnersParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_parses_the_per_school_grade_breakdown_sheet_aggregating_to_district(): void
    {
        $path = $this->xlsxFixture([
            'By LEA, School and Grade_6' => [
                ['AUN', 'LEA Name', 'School Number', 'School Name', 'K', '1', 'Total'],
                [124157203, 'Phoenixville Area SD', '1001', 'Elementary A', 10, 5, 15],
                [124157203, 'Phoenixville Area SD', '1002', 'Elementary B', 10, 5, 15],
                // A "<name> Total" pseudo-row with a non-numeric AUN, same
                // as the real workbooks - must be skipped, not summed in.
                ['Phoenixville Area SD Total', '', '', '', 20, 10, 30],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(['name' => 'Phoenixville Area SD', 'county' => null, 'lea_type' => null], $table->districts['124157203']);
        $this->assertSame(['K' => 20.0, '1' => 10.0], $table->amounts['124157203']);
    }

    public function test_tolerates_the_comma_and_spacing_variants_of_the_sheet_name(): void
    {
        $path = $this->xlsxFixture([
            'By LEA,School and Grade_6' => [
                ['AUN', 'LEA Name', 'K'],
                [124157203, 'Phoenixville Area SD', 10],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(['K' => 10.0], $table->amounts['124157203']);
    }

    public function test_falls_back_to_the_sole_sheet_when_no_name_matches(): void
    {
        // 2016-17's single-sheet workbook: no per-grade breakdown at all,
        // just one school-level total column.
        $path = $this->xlsxFixture([
            'LEP Students by School' => [
                ['AUN', 'LEA Name', 'LEP Students'],
                [124157203, 'Phoenixville Area SD', 42],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(['LEP Students' => 42.0], $table->amounts['124157203']);
    }

    public function test_throws_when_multiple_sheets_exist_and_none_match_the_pattern(): void
    {
        $path = $this->xlsxFixture([
            'Sheet A' => [['AUN', 'K'], [124157203, 10]],
            'Sheet B' => [['AUN', 'K'], [124157203, 20]],
        ]);

        $this->expectException(PDEClientException::class);

        $this->parser()->parse($path);
    }

    private function parser(): EnglishLearnersParser
    {
        return new EnglishLearnersParser(new SpreadsheetReader());
    }
}
