<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\ActOneIndexParser;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class ActOneIndexParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    /**
     * @return list<list<mixed>>
     */
    private function headerAndDataRows(): array
    {
        return [
            ['AUN', 'School District', 'County', '2024-25', '2023-24', '2022-23'],
            [124157203, 'Phoenixville Area SD', 'Chester', 0.041, 0.035, 0.028],
        ];
    }

    public function test_parses_one_column_per_school_year(): void
    {
        $path = $this->xlsxFixture(['SD Adj Index History' => $this->headerAndDataRows()]);

        $table = $this->parser()->parseYear($path, FiscalYear::parse('2024-25'));

        $this->assertSame([
            '124157203' => ['name' => 'Phoenixville Area SD', 'county' => 'Chester'],
        ], $table->districts);

        $this->assertSame(0.041, $table->rows['124157203'][0]['index']);
    }

    public function test_a_different_year_reads_its_own_column(): void
    {
        $path = $this->xlsxFixture(['SD Adj Index History' => $this->headerAndDataRows()]);

        $table = $this->parser()->parseYear($path, FiscalYear::parse('2023-24'));

        $this->assertSame(0.035, $table->rows['124157203'][0]['index']);
    }

    public function test_available_years_returns_every_year_column_newest_first(): void
    {
        $path = $this->xlsxFixture(['SD Adj Index History' => $this->headerAndDataRows()]);

        $years = $this->parser()->availableYears($path);

        $this->assertSame(
            ['2024-2025', '2023-2024', '2022-2023'],
            array_map(fn (FiscalYear $year) => $year->long(), $years),
        );
    }

    public function test_locates_the_header_row_past_leading_title_rows(): void
    {
        $rows = [
            ['Act 1 Index'],
            ['School District Adjusted Index'],
            ...$this->headerAndDataRows(),
        ];

        $path = $this->xlsxFixture(['SD Adj Index History' => $rows]);

        $table = $this->parser()->parseYear($path, FiscalYear::parse('2024-25'));

        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_skips_rows_with_a_malformed_aun(): void
    {
        $rows = $this->headerAndDataRows();
        $rows[] = ['Statewide', 'State Total', 'Statewide', 0.05, 0.04, 0.03];

        $path = $this->xlsxFixture(['SD Adj Index History' => $rows]);

        $table = $this->parser()->parseYear($path, FiscalYear::parse('2024-25'));

        $this->assertCount(1, $table->districts);
    }

    public function test_throws_when_requested_year_has_no_column(): void
    {
        $path = $this->xlsxFixture(['SD Adj Index History' => $this->headerAndDataRows()]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('no column for [2020-2021]');

        $this->parser()->parseYear($path, FiscalYear::parse('2020-21'));
    }

    public function test_throws_when_no_sheet_has_a_header_row_with_an_aun_column(): void
    {
        $path = $this->xlsxFixture([
            'SD Adj Index History' => [
                ['Act 1 Index'],
                ['No AUN column anywhere in this sheet.'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('header row with an AUN column');

        $this->parser()->parseYear($path, FiscalYear::parse('2024-25'));
    }

    private function parser(): ActOneIndexParser
    {
        return new ActOneIndexParser(new SpreadsheetReader());
    }
}
