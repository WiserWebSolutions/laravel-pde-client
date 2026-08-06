<?php

namespace WiserWebSolutions\PDEClient\Tests\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Enrollment\Parsing\PublicEnrollmentParser;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class PublicEnrollmentParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_parses_the_modern_one_row_per_lea_shape(): void
    {
        $path = $this->xlsxFixture([
            'LEA' => [
                ['AUN', 'LEA Name', 'County', 'PKF', 'K5F', '001', 'Total'],
                [124157203, 'Phoenixville Area SD', 'Chester', 10, 100, 110, 220],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame([
            '124157203' => ['name' => 'Phoenixville Area SD', 'county' => 'Chester', 'lea_type' => null],
        ], $table->districts);
        $this->assertSame(['PKF' => 10.0, 'K5F' => 100.0, '001' => 110.0], $table->amounts['124157203']);
        // "Total" is a non-grade column - it must never show up as its own amount.
        $this->assertArrayNotHasKey('Total', $table->amounts['124157203']);
    }

    public function test_sums_multiple_school_rows_into_one_district_total(): void
    {
        // The pre-2010-11 shape is one row per SCHOOL, not per LEA - the
        // parser must aggregate rather than overwrite.
        $path = $this->xlsxFixture([
            'School' => [
                ['AUN', 'LEA Name', 'K5F', '001'],
                [124157203, 'Phoenixville Area SD', 40, 50],
                [124157203, 'Phoenixville Area SD', 60, 60],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(['K5F' => 100.0, '001' => 110.0], $table->amounts['124157203']);
    }

    public function test_prefers_the_data_file_sheet_over_the_report_view_sheet(): void
    {
        // "Report View" merge-cells its meta columns down each district's
        // block of school rows, blank on every row but the first - only the
        // "Data File" sheet has every column populated on every row, so it
        // must win even though "Report View" is listed first.
        $path = $this->xlsxFixture([
            'LEA - Report View' => [
                ['AUN', 'LEA Name', 'K5F', '001'],
                [124157203, 'Phoenixville Area SD', 999, 999],
            ],
            'LEA - Data File' => [
                ['AUN', 'LEA Name', 'K5F', '001'],
                [124157203, 'Phoenixville Area SD', 100, 110],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(['K5F' => 100.0, '001' => 110.0], $table->amounts['124157203']);
    }

    public function test_skips_rows_with_a_malformed_aun(): void
    {
        $path = $this->xlsxFixture([
            'LEA' => [
                ['AUN', 'LEA Name', 'K5F'],
                ['District Total', 'Statewide', 99999],
                [124157203, 'Phoenixville Area SD', 100],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertCount(1, $table->districts);
        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_throws_when_no_sheet_has_an_aun_column(): void
    {
        $path = $this->xlsxFixture([
            'County-District-School' => [
                ['County', 'District', 'School', 'Enrollment'],
                ['Chester', 'Phoenixville Area SD', 'Elementary', 500],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('predates PDE including AUN');

        $this->parser()->parse($path);
    }

    private function parser(): PublicEnrollmentParser
    {
        return new PublicEnrollmentParser(new SpreadsheetReader());
    }
}
