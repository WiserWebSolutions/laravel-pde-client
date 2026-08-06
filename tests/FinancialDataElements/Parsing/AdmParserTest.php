<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\AdmParser;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class AdmParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_parses_the_modern_shape_with_pde363_columns_and_a_breakdown(): void
    {
        $path = $this->xlsxFixture([
            '2024-25 ADM-WADM' => [
                ['AUN', 'School District', 'County', 'Average Daily Membership', 'Weighted Average Daily Membership',
                    'Adjusted ADM', 'Nonresident ADM', 'Total ADM for PDE-363', 'Special Education ADM',
                    'Adjustment Factor', 'ADM Kindergarten'],
                [124157203, 'Phoenixville Area SD', 'Chester', 200, 210, 205, 5, 199, 15, 1.05, 50],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame([
            '124157203' => ['name' => 'Phoenixville Area SD', 'county' => 'Chester'],
        ], $table->districts);

        $row = $table->rows['124157203'][0];
        $this->assertSame(200.0, $row['adm']);
        $this->assertSame(210.0, $row['wadm']);
        $this->assertSame(205.0, $row['adjusted_adm']);
        $this->assertSame(5.0, $row['nonresident_adm']);
        $this->assertSame(199.0, $row['total_adm_pde363']);
        $this->assertSame(15.0, $row['special_education_adm']);
        $this->assertSame(1.05, $row['adjustment_factor']);
        $this->assertSame(['ADM Kindergarten' => 50.0], $row['breakdown']);
    }

    public function test_older_years_leave_the_pde363_columns_null(): void
    {
        // Pre-2024-25 workbooks simply don't have these three columns at all.
        $path = $this->xlsxFixture([
            '2015-16 ADM-WADM' => [
                ['AUN', 'School District', 'County', 'Average Daily Membership', 'Weighted Average Daily Membership',
                    'Adjusted ADM', 'Adjustment Factor'],
                [124157203, 'Phoenixville Area SD', 'Chester', 150, 155, 152, 1.02],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $row = $table->rows['124157203'][0];
        $this->assertSame(150.0, $row['adm']);
        $this->assertNull($row['nonresident_adm']);
        $this->assertNull($row['total_adm_pde363']);
        $this->assertNull($row['special_education_adm']);
        $this->assertSame([], $row['breakdown']);
    }

    public function test_picks_the_sheet_containing_the_adm_wadm_fragment_even_if_not_first(): void
    {
        $path = $this->xlsxFixture([
            'CS ADM by SD' => [
                ['AUN', 'School District', 'Average Daily Membership'],
                [124157203, 'Phoenixville Area SD', 999],
            ],
            '2024-25 ADM-WADM' => [
                ['AUN', 'School District', 'Average Daily Membership'],
                [124157203, 'Phoenixville Area SD', 200],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(200.0, $table->rows['124157203'][0]['adm']);
    }

    public function test_skips_rows_with_a_malformed_aun(): void
    {
        $path = $this->xlsxFixture([
            'ADM-WADM' => [
                ['AUN', 'School District', 'Average Daily Membership'],
                ['State Total', 'Statewide', 999999],
                [124157203, 'Phoenixville Area SD', 200],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertCount(1, $table->districts);
        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_throws_when_no_sheet_name_contains_the_adm_wadm_fragment(): void
    {
        $path = $this->xlsxFixture([
            'Summary' => [
                ['AUN', 'School District', 'Average Daily Membership'],
                [124157203, 'Phoenixville Area SD', 200],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('ADM-WADM');

        $this->parser()->parse($path);
    }

    public function test_throws_when_the_header_row_is_missing_the_adm_column(): void
    {
        $path = $this->xlsxFixture([
            'ADM-WADM' => [
                ['AUN', 'School District', 'County'],
                [124157203, 'Phoenixville Area SD', 'Chester'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('missing the AUN or ADM column');

        $this->parser()->parse($path);
    }

    private function parser(): AdmParser
    {
        return new AdmParser(new SpreadsheetReader());
    }
}
