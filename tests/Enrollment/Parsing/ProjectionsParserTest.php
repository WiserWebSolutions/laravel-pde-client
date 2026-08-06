<?php

namespace WiserWebSolutions\PDEClient\Tests\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Enrollment\Parsing\ProjectionsParser;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class ProjectionsParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    private function fixture(): string
    {
        return $this->xlsxFixture([
            'Enrollment Projection Data' => [
                ['Datatype', 'AUN', 'School Year', 'LEA Name', 'County', 'K', '1'],
                ['Actual', 124157203, '2023-2024', 'Phoenixville Area SD', 'Chester', 500, 480],
                ['Projection', 124157203, '2024-2025', 'Phoenixville Area SD', 'Chester', 510, 490],
                ['Projection', 124157203, '2025-2026', 'Phoenixville Area SD', 'Chester', 520, 495],
            ],
        ]);
    }

    public function test_available_years_only_counts_projection_rows(): void
    {
        $years = $this->parser()->availableYears($this->fixture());

        $this->assertSame(['2025-2026', '2024-2025'], array_map(fn (FiscalYear $y) => $y->long(), $years));
    }

    public function test_parse_year_only_returns_the_projection_row_for_that_year(): void
    {
        $table = $this->parser()->parseYear($this->fixture(), FiscalYear::parse('2024-2025'));

        $this->assertSame(['K' => 510.0, '1' => 490.0], $table->amounts['124157203']);
        $this->assertSame('Phoenixville Area SD', $table->districts['124157203']['name']);
        $this->assertSame('Chester', $table->districts['124157203']['county']);
    }

    public function test_actual_rows_never_leak_into_a_projection_years_totals(): void
    {
        // 2023-2024 only has an Actual row, no Projection row - it must
        // come back empty, not silently fall back to the actual data.
        $table = $this->parser()->parseYear($this->fixture(), FiscalYear::parse('2023-2024'));

        $this->assertArrayNotHasKey('124157203', $table->amounts);
    }

    public function test_throws_when_the_sheet_lacks_expected_columns(): void
    {
        $path = $this->xlsxFixture([
            'Enrollment Projection Data' => [
                ['Something', 'Else'],
                ['a', 'b'],
            ],
        ]);

        $this->expectException(PDEClientException::class);

        $this->parser()->availableYears($path);
    }

    private function parser(): ProjectionsParser
    {
        return new ProjectionsParser(new SpreadsheetReader());
    }
}
