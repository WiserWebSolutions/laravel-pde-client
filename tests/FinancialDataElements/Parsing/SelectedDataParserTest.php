<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\SelectedDataParser;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class SelectedDataParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    /**
     * @return list<list<mixed>>
     */
    private function headerAndDataRows(): array
    {
        return [
            ['Selected Data'],
            ['2022-2023 School Year'],
            ['County', 'AUN', 'School District', '2024-25 Aid Ratio', 'Rank', 'WADM', 'ADM', 'Rank',
                'EQ Mills', 'Rank', 'Pop Per Sq Mile', 'Rank', 'AIE Per WADM', 'Rank', 'Total Exp Per ADM', 'Rank'],
            ['Chester', 124157203, 'Phoenixville Area SD', 0.35, 120, 1000, 950, 110,
                18.5, 75, 2500, 50, 9500, 200, 15000, 210],
        ];
    }

    public function test_parses_every_metric_and_its_positional_rank_column(): void
    {
        $path = $this->xlsxFixture(['Selected Data' => $this->headerAndDataRows()]);

        $table = $this->parser()->parse($path);

        $this->assertSame([
            '124157203' => ['name' => 'Phoenixville Area SD', 'county' => 'Chester'],
        ], $table->districts);

        $row = $table->rows['124157203'][0];
        $this->assertSame(0.35, $row['aid_ratio']);
        $this->assertSame(120.0, $row['aid_ratio_rank']);
        $this->assertSame(1000.0, $row['wadm']);
        $this->assertSame(950.0, $row['adm']);
        $this->assertSame(110.0, $row['adm_rank']);
        $this->assertSame(18.5, $row['equalized_mills']);
        $this->assertSame(75.0, $row['equalized_mills_rank']);
        $this->assertSame(2500.0, $row['population_per_square_mile']);
        $this->assertSame(50.0, $row['population_per_square_mile_rank']);
        $this->assertSame(9500.0, $row['instruction_expense_per_wadm']);
        $this->assertSame(200.0, $row['instruction_expense_per_wadm_rank']);
        $this->assertSame(15000.0, $row['total_expenditure_per_adm']);
        $this->assertSame(210.0, $row['total_expenditure_per_adm_rank']);
    }

    public function test_wadm_has_no_rank_column(): void
    {
        // WADM's neighbor column is ADM, not "Rank" - proves the positional
        // rank detection doesn't misfire and borrow ADM's value for it.
        $path = $this->xlsxFixture(['Selected Data' => $this->headerAndDataRows()]);

        $table = $this->parser()->parse($path);

        $this->assertArrayNotHasKey('wadm_rank', $table->rows['124157203'][0]);
    }

    public function test_locates_the_header_row_past_leading_title_rows(): void
    {
        $rows = $this->headerAndDataRows();
        $this->assertCount(4, $rows); // 2 title rows + header + 1 data row, sanity check on the fixture itself

        $path = $this->xlsxFixture(['Selected Data' => $rows]);

        $table = $this->parser()->parse($path);

        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_skips_rows_with_a_malformed_aun(): void
    {
        $rows = $this->headerAndDataRows();
        $rows[] = ['Statewide', 'State Total', 'Statewide', 0.5, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1];

        $path = $this->xlsxFixture(['Selected Data' => $rows]);

        $table = $this->parser()->parse($path);

        $this->assertCount(1, $table->districts);
    }

    public function test_throws_when_no_sheet_has_a_header_row_with_an_aun_column(): void
    {
        $path = $this->xlsxFixture([
            'Selected Data' => [
                ['Selected Data'],
                ['No AUN column anywhere in this sheet.'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('header row with an AUN column');

        $this->parser()->parse($path);
    }

    public function test_throws_when_the_header_row_is_missing_the_per_pupil_expenditure_columns(): void
    {
        $path = $this->xlsxFixture([
            'Selected Data' => [
                ['County', 'AUN', 'School District', 'Aid Ratio', 'WADM', 'ADM'],
                ['Chester', 124157203, 'Phoenixville Area SD', 0.35, 1000, 950],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('missing the AUN or per-pupil expenditure columns');

        $this->parser()->parse($path);
    }

    private function parser(): SelectedDataParser
    {
        return new SelectedDataParser(new SpreadsheetReader());
    }
}
