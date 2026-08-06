<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\RealEstateTaxRateParser;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class RealEstateTaxRateParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_parses_a_single_county_district(): void
    {
        $path = $this->xlsxFixture([
            'Real Estate Tax Rates' => [
                ['AUN', 'School District', 'County', 'Millage', 'Municipality / Other Info', 'Community College Millage'],
                [124157203, 'Phoenixville Area SD', 'Chester', 20.5, null, 1.2],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(['124157203' => ['name' => 'Phoenixville Area SD']], $table->districts);
        $this->assertSame([
            ['county' => 'Chester', 'notes' => null, 'mills' => 20.5, 'community_college_mills' => 1.2],
        ], $table->rows['124157203']);
    }

    public function test_a_district_spanning_multiple_counties_gets_one_row_per_county(): void
    {
        $path = $this->xlsxFixture([
            'Real Estate Tax Rates' => [
                ['AUN', 'School District', 'County', 'Millage', 'Municipality / Other Info'],
                [124157203, 'Phoenixville Area SD', 'Chester', 20.5, null],
                [124157203, 'Phoenixville Area SD', 'Montgomery', 21.0, null],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertCount(2, $table->rows['124157203']);
        $this->assertSame('Chester', $table->rows['124157203'][0]['county']);
        $this->assertSame('Montgomery', $table->rows['124157203'][1]['county']);
    }

    public function test_notes_column_carries_free_text_verbatim(): void
    {
        $path = $this->xlsxFixture([
            'Real Estate Tax Rates' => [
                ['AUN', 'School District', 'County', 'Millage', 'Municipality / Other Info'],
                [124157203, 'Phoenixville Area SD', 'Chester', 20.5, 'Oil/Gas/Mineral Properties'],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame('Oil/Gas/Mineral Properties', $table->rows['124157203'][0]['notes']);
    }

    public function test_uses_the_first_sheet_with_an_aun_header_and_ignores_the_rest(): void
    {
        $path = $this->xlsxFixture([
            'Notes' => [
                ['This workbook lists real estate tax rates by county.'],
            ],
            'Real Estate Tax Rates' => [
                ['AUN', 'School District', 'County', 'Millage'],
                [124157203, 'Phoenixville Area SD', 'Chester', 20.5],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertSame(20.5, $table->rows['124157203'][0]['mills']);
    }

    public function test_skips_rows_with_a_malformed_aun(): void
    {
        $path = $this->xlsxFixture([
            'Real Estate Tax Rates' => [
                ['AUN', 'School District', 'County', 'Millage'],
                ['State Total', 'Statewide', '', 99],
                [124157203, 'Phoenixville Area SD', 'Chester', 20.5],
            ],
        ]);

        $table = $this->parser()->parse($path);

        $this->assertCount(1, $table->districts);
        $this->assertArrayHasKey('124157203', $table->districts);
    }

    public function test_throws_when_no_sheet_has_an_aun_column(): void
    {
        $path = $this->xlsxFixture([
            'Notes' => [
                ['This workbook has no tabular data at all.'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('header row with an AUN column');

        $this->parser()->parse($path);
    }

    public function test_throws_when_the_header_row_is_missing_the_millage_column(): void
    {
        $path = $this->xlsxFixture([
            'Real Estate Tax Rates' => [
                ['AUN', 'School District', 'County'],
                [124157203, 'Phoenixville Area SD', 'Chester'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('missing the AUN or millage column');

        $this->parser()->parse($path);
    }

    private function parser(): RealEstateTaxRateParser
    {
        return new RealEstateTaxRateParser(new SpreadsheetReader());
    }
}
