<?php

namespace WiserWebSolutions\PDEClient\Tests\Graduation\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Graduation\Parsing\CohortRatesParser;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class CohortRatesParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_melts_demographic_group_columns_into_one_row_per_group(): void
    {
        $path = $this->xlsxFixture([
            '4 Year Grad Rate by LEA' => [
                ['AUN', 'LEA', 'LEA Type', 'Grads', 'Cohort', 'Cohort Grad Rate', 'Male Grad Rate', 'EL Grad Rate'],
                [124157203, 'Phoenixville Area SD', 'SD', 90, 100, 0.9, 0.85, 0.6],
            ],
        ]);

        $table = (new CohortRatesParser(new SpreadsheetReader()))->parse($path);

        $this->assertSame(['name' => 'Phoenixville Area SD', 'county' => null, 'lea_type' => 'SD'], $table->districts['124157203']);

        $byGroup = [];
        foreach ($table->rows['124157203'] as $row) {
            $byGroup[$row['group']] = $row;
        }

        $this->assertSame(90.0, $byGroup['Total']['graduates']);
        $this->assertSame(100.0, $byGroup['Total']['cohort_size']);
        $this->assertSame(0.9, $byGroup['Total']['rate']);

        // Demographic group columns only ever carry a rate, never counts.
        $this->assertNull($byGroup['Male']['graduates']);
        $this->assertSame(0.85, $byGroup['Male']['rate']);
        $this->assertSame(0.6, $byGroup['EL']['rate']);
    }

    public function test_older_workbook_header_names_are_recognized_without_a_bogus_extra_total_group(): void
    {
        $path = $this->xlsxFixture([
            'Grad Rate by LEA' => [
                ['AUN', 'LEA', 'Total Grads', 'Total Cohort', 'Total Grad Rate', 'Male Grad Rate'],
                [124157203, 'Phoenixville Area SD', 80, 90, 0.888, 0.7],
            ],
        ]);

        $table = (new CohortRatesParser(new SpreadsheetReader()))->parse($path);
        $rows = $table->rows['124157203'];

        // "Total Grad Rate" must be consumed as the Total group's rate, not
        // ALSO melted into its own bogus "Total" demographic-group row.
        $totalRows = array_filter($rows, fn (array $row) => $row['group'] === 'Total');
        $this->assertCount(1, $totalRows);
        $this->assertSame(80.0, reset($totalRows)['graduates']);
    }

    public function test_throws_when_no_sheet_matches_the_grad_rate_by_lea_fragment(): void
    {
        $path = $this->xlsxFixture([
            'Summary' => [['AUN'], [124157203]],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('Grad Rate by LEA');

        (new CohortRatesParser(new SpreadsheetReader()))->parse($path);
    }
}
