<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\GfbWorkbookParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class GfbWorkbookParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_parses_revenue_codes_from_the_modern_sheet_name(): void
    {
        $path = $this->xlsxFixture([
            'Rev_BegFB' => [
                ['AUN', 'InstName', 'CountyName', 6111, 6112, 9310],
                [124157203, 'Phoenixville Area SD', 'Chester', 1000, 500, 200],
            ],
        ]);

        $table = (new GfbWorkbookParser(new SpreadsheetReader()))->revenues($path);

        $this->assertSame(['name' => 'Phoenixville Area SD', 'county' => 'Chester'], $table->districts['124157203']);
        $this->assertSame(['6111' => 1000.0, '6112' => 500.0, '9310' => 200.0], $table->amounts['124157203']);
    }

    public function test_falls_back_to_an_older_revenue_sheet_name(): void
    {
        $path = $this->xlsxFixture([
            'Rev_BeginFundBal' => [
                ['AUN', 'InstName', 'CountyName', 6111],
                [124157203, 'Phoenixville Area SD', 'Chester', 900],
            ],
        ]);

        $table = (new GfbWorkbookParser(new SpreadsheetReader()))->revenues($path);

        $this->assertSame(['6111' => 900.0], $table->amounts['124157203']);
    }

    public function test_expenditures_aggregate_function_object_pairs_to_the_function_code(): void
    {
        $path = $this->xlsxFixture([
            'Exp' => [
                ['AUN', 'InstName', 'CountyName', '1100-100', '1100-200', '1200-100'],
                [124157203, 'Phoenixville Area SD', 'Chester', 1000, 500, 300],
            ],
        ]);

        $table = (new GfbWorkbookParser(new SpreadsheetReader()))->expenditures($path);

        // 1100-100 and 1100-200 both roll up into function code "1100".
        $this->assertSame(['1100' => 1500.0, '1200' => 300.0], $table->amounts['124157203']);
    }

    public function test_throws_when_no_known_revenue_sheet_exists(): void
    {
        $path = $this->xlsxFixture([
            'Something Else' => [['AUN'], [124157203]],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('None of the expected GFB sheets');

        (new GfbWorkbookParser(new SpreadsheetReader()))->revenues($path);
    }

    public function test_throws_when_the_sheet_has_no_account_code_columns(): void
    {
        $path = $this->xlsxFixture([
            'Exp' => [
                ['AUN', 'InstName', 'CountyName'],
                [124157203, 'Phoenixville Area SD', 'Chester'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('does not have the expected AUN/account-code columns');

        (new GfbWorkbookParser(new SpreadsheetReader()))->expenditures($path);
    }
}
