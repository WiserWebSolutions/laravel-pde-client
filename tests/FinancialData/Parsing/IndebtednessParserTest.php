<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\IndebtednessParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class IndebtednessParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    /**
     * Two fund types (governmental/proprietary), each with a
     * beginning-of-year and end-of-year group, each with one debt category
     * plus a formula-only TOTAL column that must be skipped, not parsed.
     */
    private function fixture(): string
    {
        return $this->xlsxFixture([
            '2024-25' => [
                [
                    '', '', '',
                    'Governmental Fund Types -- Debt Outstanding at Beginning of Fiscal Year', '',
                    'Governmental Fund Types -- Debt Outstanding at End of Fiscal Year', '',
                    'Proprietary Fund Types -- Debt Outstanding at Beginning of Fiscal Year', '',
                    'Proprietary Fund Types -- Debt Outstanding at End of Fiscal Year', '',
                ],
                ['AUN', 'LEA Name', 'County', 'Bonds', 'TOTAL', 'Bonds', 'TOTAL', 'Leases', 'TOTAL', 'Leases', 'TOTAL'],
                [124157203, 'Phoenixville Area SD', 'Chester', 1000, 0, 1100, 0, 200, 0, 220, 0],
            ],
        ]);
    }

    public function test_available_years_reads_every_year_shaped_sheet_name(): void
    {
        $years = (new IndebtednessParser(new SpreadsheetReader()))->availableYears($this->fixture());

        $this->assertSame(['2024-2025'], array_map(fn (FiscalYear $y) => $y->long(), $years));
    }

    public function test_computes_each_groups_total_from_its_own_categories(): void
    {
        $table = (new IndebtednessParser(new SpreadsheetReader()))->parseYear($this->fixture(), FiscalYear::parse('2024-2025'));

        $rows = $table->rows['124157203'];
        $byKey = [];

        foreach ($rows as $row) {
            $byKey["{$row['fund_type']}|{$row['phase']}"] = $row;
        }

        $this->assertSame(1000.0, $byKey['governmental|beginning']['total']);
        $this->assertSame(['Bonds' => 1000.0], $byKey['governmental|beginning']['categories']);
        $this->assertSame(1100.0, $byKey['governmental|end']['total']);
        $this->assertSame(200.0, $byKey['proprietary|beginning']['total']);
        $this->assertSame(220.0, $byKey['proprietary|end']['total']);
    }

    public function test_computes_all_fund_types_as_governmental_plus_proprietary(): void
    {
        $table = (new IndebtednessParser(new SpreadsheetReader()))->parseYear($this->fixture(), FiscalYear::parse('2024-2025'));

        $rows = $table->rows['124157203'];
        $byKey = [];

        foreach ($rows as $row) {
            $byKey["{$row['fund_type']}|{$row['phase']}"] = $row;
        }

        $this->assertSame(1200.0, $byKey['all|beginning']['total']); // 1000 (gov) + 200 (prop)
        $this->assertSame(1320.0, $byKey['all|end']['total']); // 1100 (gov) + 220 (prop)
        $this->assertSame([], $byKey['all|beginning']['categories']);
    }

    public function test_the_total_column_itself_is_never_parsed_as_a_category(): void
    {
        $table = (new IndebtednessParser(new SpreadsheetReader()))->parseYear($this->fixture(), FiscalYear::parse('2024-2025'));

        foreach ($table->rows['124157203'] as $row) {
            $this->assertArrayNotHasKey('TOTAL', $row['categories']);
        }
    }

    public function test_throws_when_the_sheet_has_no_fund_type_columns(): void
    {
        $path = $this->xlsxFixture([
            '2024-25' => [
                ['', ''],
                ['AUN', 'LEA Name'],
                [124157203, 'Phoenixville Area SD'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('has an AUN header row but no fund-type/phase columns');

        (new IndebtednessParser(new SpreadsheetReader()))->parseYear($path, FiscalYear::parse('2024-2025'));
    }
}
