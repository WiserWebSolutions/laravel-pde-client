<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\FundBalanceParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class FundBalanceParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_available_years_reads_every_year_shaped_sheet_name(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [['AUN'], [124157203]],
            'Notes' => [['Ignore me']],
        ]);

        $years = (new FundBalanceParser(new SpreadsheetReader()))->availableYears($path);

        $this->assertSame(['2024-2025'], array_map(fn (FiscalYear $y) => $y->long(), $years));
    }

    public function test_parses_committed_assigned_and_unassigned_columns(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [
                ['AUN', 'LEA Name', 'County', 'Committed', 'Assigned', 'Unassigned'],
                [124157203, 'Phoenixville Area SD', 'Chester', 100, 200, 300],
            ],
        ]);

        $table = (new FundBalanceParser(new SpreadsheetReader()))->parseYear($path, FiscalYear::parse('2024-2025'));

        $this->assertSame(['name' => 'Phoenixville Area SD', 'county' => 'Chester'], $table->districts['124157203']);
        $this->assertSame([
            ['committed' => 100.0, 'assigned' => 200.0, 'unassigned' => 300.0],
        ], $table->rows['124157203']);
    }

    public function test_assigned_column_is_not_confused_with_unassigned(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [
                ['AUN', 'Unassigned', 'Assigned'],
                [124157203, 300, 200],
            ],
        ]);

        $table = (new FundBalanceParser(new SpreadsheetReader()))->parseYear($path, FiscalYear::parse('2024-2025'));

        $row = $table->rows['124157203'][0];
        $this->assertSame(200.0, $row['assigned']);
        $this->assertSame(300.0, $row['unassigned']);
    }

    public function test_throws_when_the_sheet_has_no_aun_header_row(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [
                ['Not', 'A', 'Header'],
                [1, 2, 3],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('has no header row containing AUN');

        (new FundBalanceParser(new SpreadsheetReader()))->parseYear($path, FiscalYear::parse('2024-2025'));
    }
}
