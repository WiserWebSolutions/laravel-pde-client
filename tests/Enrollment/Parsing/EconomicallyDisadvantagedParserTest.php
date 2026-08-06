<?php

namespace WiserWebSolutions\PDEClient\Tests\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Enrollment\Parsing\EconomicallyDisadvantagedParser;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class EconomicallyDisadvantagedParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    /**
     * PDE's single, in-place-updated "Ten Year Low Income and Enrollment
     * History" workbook: one merge-celled year super-header spanning a
     * repeating 3-column group (count/enrollment/percent) per year.
     */
    private function fixture(): string
    {
        return $this->xlsxFixture([
            'Low Income' => [
                ['', '', '', '2016-2017', '', '', '2024-2025', '', ''],
                ['AUN', 'LEA', 'TYPE', 'Low Income', 'Enrollment', 'Percent', 'Low Income', 'Enrollment', 'Percent'],
                [124157203, 'Phoenixville Area SD', 'SD', 200, 800, 25, 500, 1000, 50],
            ],
        ]);
    }

    public function test_available_years_reads_every_year_column_group(): void
    {
        $years = $this->parser()->availableYears($this->fixture());

        $this->assertSame(['2024-2025', '2016-2017'], array_map(fn (FiscalYear $y) => $y->long(), $years));
    }

    public function test_parses_each_years_column_group_independently(): void
    {
        $path = $this->fixture();

        $recent = $this->parser()->parseYear($path, FiscalYear::parse('2024-2025'));
        $this->assertSame([
            'economically_disadvantaged' => 500.0,
            'enrollment' => 1000.0,
            'percent' => 50.0,
        ], $recent->rows['124157203'][0]);

        $older = $this->parser()->parseYear($path, FiscalYear::parse('2016-2017'));
        $this->assertSame([
            'economically_disadvantaged' => 200.0,
            'enrollment' => 800.0,
            'percent' => 25.0,
        ], $older->rows['124157203'][0]);

        $this->assertSame([
            'name' => 'Phoenixville Area SD',
            'lea_type' => 'SD',
            'county' => null,
        ], $recent->districts['124157203']);
    }

    public function test_throws_for_a_year_with_no_column_group(): void
    {
        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('no column group');

        $this->parser()->parseYear($this->fixture(), FiscalYear::parse('2020-2021'));
    }

    private function parser(): EconomicallyDisadvantagedParser
    {
        return new EconomicallyDisadvantagedParser(new SpreadsheetReader());
    }
}
