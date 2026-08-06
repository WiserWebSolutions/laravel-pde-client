<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\AfrDetailWorkbookParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class AfrDetailWorkbookParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_available_years_reads_every_year_shaped_sheet_name(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [['AUN'], [124157203]],
            '2023-24' => [['AUN'], [124157203]],
            'Notes' => [['Ignore me']],
        ]);

        $years = (new AfrDetailWorkbookParser(new SpreadsheetReader()))->availableYears($path);

        $this->assertSame(['2024-2025', '2023-2024'], array_map(fn (FiscalYear $y) => $y->long(), $years));
    }

    public function test_extracts_the_account_code_from_the_tail_of_the_header_label(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [
                ['AUN', 'LEA Name', 'County', "Current Real Estate Taxes\n6111", "Regular\nPrograms 1110", 'Total Expenditures'],
                [124157203, 'Phoenixville Area SD', 'Chester', 5000, 3000, 999999],
            ],
        ]);

        $table = (new AfrDetailWorkbookParser(new SpreadsheetReader()))->parseYear($path, FiscalYear::parse('2024-2025'));

        $this->assertSame(['name' => 'Phoenixville Area SD', 'county' => 'Chester'], $table->districts['124157203']);
        $this->assertSame(['6111' => 5000.0, '1110' => 3000.0], $table->amounts['124157203']);
        // The un-coded "Total Expenditures" column must never leak in as its own amount.
        $this->assertArrayNotHasKey('Total Expenditures', $table->amounts['124157203']);
        $this->assertSame('Current Real Estate Taxes', $table->accountNames['6111']);
    }

    public function test_accepts_school_district_as_the_name_column_label(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [
                ['AUN', 'School District', "Tuition 1110"],
                [124157203, 'Phoenixville Area SD', 100],
            ],
        ]);

        $table = (new AfrDetailWorkbookParser(new SpreadsheetReader()))->parseYear($path, FiscalYear::parse('2024-2025'));

        $this->assertSame('Phoenixville Area SD', $table->districts['124157203']['name']);
    }

    public function test_throws_when_the_sheet_has_no_account_code_columns(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [
                ['AUN', 'LEA Name', 'Total Expenditures'],
                [124157203, 'Phoenixville Area SD', 999],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('does not have the expected AUN/account-code columns');

        (new AfrDetailWorkbookParser(new SpreadsheetReader()))->parseYear($path, FiscalYear::parse('2024-2025'));
    }
}
