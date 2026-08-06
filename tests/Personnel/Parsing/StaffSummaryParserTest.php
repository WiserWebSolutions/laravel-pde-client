<?php

namespace WiserWebSolutions\PDEClient\Tests\Personnel\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Personnel\Parsing\StaffSummaryParser;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class StaffSummaryParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_parses_one_row_per_category_with_null_for_missing_averages(): void
    {
        $path = $this->xlsxFixture([
            'LEA_Averages' => [
                [
                    'AUN', 'LEA Name', 'County', 'LEA Type',
                    'PP', 'PP-F', 'PP-M', 'Sal-PP', 'Svc-PP', 'Lea-PP', 'Ed-PP',
                    'AD', 'CT', 'Sal-CT', 'CO', 'OT', 'Svcv-Ot',
                ],
                [124157203, 'Phoenixville Area SD', 'Chester', 'SD', 200, 120, 80, 95000, 10, 8, 5, 5, 100, 52000, 3, 2, 6],
            ],
        ]);

        $table = (new StaffSummaryParser(new SpreadsheetReader()))->parse($path);

        $this->assertSame([
            'name' => 'Phoenixville Area SD',
            'county' => 'Chester',
            'lea_type' => 'SD',
        ], $table->districts['124157203']);

        $byCategory = [];
        foreach ($table->rows['124157203'] as $row) {
            $byCategory[$row['category']] = $row;
        }

        $this->assertSame(200.0, $byCategory['professional']['count']);
        $this->assertSame(120.0, $byCategory['professional']['female']);
        $this->assertSame(95000.0, $byCategory['professional']['salary']);

        $this->assertSame(5.0, $byCategory['administrator']['count']);
        $this->assertNull($byCategory['administrator']['salary']);

        $this->assertSame(100.0, $byCategory['classroom_teacher']['count']);
        $this->assertSame(52000.0, $byCategory['classroom_teacher']['salary']);
        $this->assertNull($byCategory['classroom_teacher']['service']);

        $this->assertSame(3.0, $byCategory['coordinator']['count']);

        // "Svcv-Ot" is a known PDE header typo for the "other" category's
        // service-years column - must resolve via the alias, not come back null.
        $this->assertSame(2.0, $byCategory['other']['count']);
        $this->assertSame(6.0, $byCategory['other']['service']);
    }

    public function test_skips_rows_with_a_malformed_aun(): void
    {
        $path = $this->xlsxFixture([
            'LEA_Averages' => [
                ['AUN', 'PP', 'AD', 'CT', 'CO', 'OT'],
                ['State Total', 99999, 99999, 99999, 99999, 99999],
                [124157203, 200, 5, 100, 3, 2],
            ],
        ]);

        $table = (new StaffSummaryParser(new SpreadsheetReader()))->parse($path);

        $this->assertCount(1, $table->districts);
        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_throws_when_a_category_headcount_column_is_missing(): void
    {
        $path = $this->xlsxFixture([
            'LEA_Averages' => [
                ['AUN', 'PP', 'AD', 'CT', 'CO'],
                [124157203, 200, 5, 100, 3],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('missing the [OT] headcount column');

        (new StaffSummaryParser(new SpreadsheetReader()))->parse($path);
    }

    public function test_throws_when_the_sheet_has_no_aun_header_row(): void
    {
        $path = $this->xlsxFixture([
            'LEA_Averages' => [
                ['Not', 'A', 'Header'],
                [1, 2, 3],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('has no header row containing AUN');

        (new StaffSummaryParser(new SpreadsheetReader()))->parse($path);
    }
}
