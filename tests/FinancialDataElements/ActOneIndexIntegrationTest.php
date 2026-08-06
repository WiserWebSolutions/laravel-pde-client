<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialData\FinancialYearSummary;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRecord;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * Proves the real Act 1 Index pipeline - Finder scrapes the listing page and
 * categorizes each link by filename (the page also lists the current year's
 * standalone listing and the statewide base index history PDF, neither of
 * which this package models), Locator picks the adjusted index history
 * workbook, downloads and parses it - actually wires together end to end,
 * same pattern as FinancialDataElementsIntegrationTest.
 */
class ActOneIndexIntegrationTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_act_one_index_downloads_and_parses_the_real_pipeline(): void
    {
        $workbook = $this->xlsxFixture([
            'SD Adj Index History' => [
                ['AUN', 'School District', 'County', '2024-25', '2023-24'],
                [124157203, 'Phoenixville Area SD', 'Chester', 0.041, 0.035],
            ],
        ]);

        Http::fake([
            'pde-client-tests.example/act-1-index' => Http::response($this->listingPageHtml()),
            'pde-client-tests.example/act1index/*' => Http::response(file_get_contents($workbook)),
        ]);

        $record = PDE::district('124157203')->year('2024-2025')->financials()->actOneIndex()->sole();

        $this->assertInstanceOf(ActOneIndexRecord::class, $record);
        $this->assertSame('Phoenixville Area SD', $record->districtName);
        $this->assertSame(0.041, $record->index);
    }

    public function test_with_act_one_index_nests_the_real_pipeline_into_the_financials_summary(): void
    {
        $gfbWorkbook = $this->xlsxFixture([
            'Rev_BegFB' => [
                ['AUN', 'InstName', 'CountyName', 6111],
                [124157203, 'Phoenixville Area SD', 'Chester', 1000],
            ],
        ]);

        $actOneIndexWorkbook = $this->xlsxFixture([
            'SD Adj Index History' => [
                ['AUN', 'School District', 'County', '2024-25'],
                [124157203, 'Phoenixville Area SD', 'Chester', 0.041],
            ],
        ]);

        Http::fake([
            'pde-client-tests.example/gfb' => Http::response($this->gfbListingPageHtml()),
            'pde-client-tests.example/files/*' => Http::response(file_get_contents($gfbWorkbook)),
            'pde-client-tests.example/act-1-index' => Http::response($this->listingPageHtml()),
            'pde-client-tests.example/act1index/*' => Http::response(file_get_contents($actOneIndexWorkbook)),
        ]);

        $summary = PDE::district('124157203')->year('2024-2025')->financials()->budget()->withActOneIndex()->sole();

        $this->assertInstanceOf(FinancialYearSummary::class, $summary);
        $this->assertInstanceOf(ActOneIndexRecord::class, $summary->actOneIndex);
        $this->assertSame(0.041, $summary->actOneIndex->index);
    }

    private function listingPageHtml(): string
    {
        $historyHref = 'https://pde-client-tests.example/act1index/ssact1%20adjindexhistory%202024-25.xlsx';
        $currentHref = 'https://pde-client-tests.example/act1index/ssact1%20adjindex%202024-25.xlsx';
        $baseHref = 'https://pde-client-tests.example/act1index/ssact1%20baseindexhistory%202024-25.pdf';

        return <<<HTML
            <html>
                <body>
                    <main>
                        <a href="{$currentHref}">2024-25 School District Adjusted Index Listing</a>
                        <a href="{$baseHref}">Base Index History</a>
                        <a href="{$historyHref}">Adjusted Index History</a>
                    </main>
                </body>
            </html>
            HTML;
    }

    private function gfbListingPageHtml(): string
    {
        $href = 'https://pde-client-tests.example/files/2024-2025-gfb-data.xlsx';

        return <<<HTML
            <html>
                <body>
                    <main>
                        <a href="{$href}">GFB Data 2024-25</a>
                    </main>
                </body>
            </html>
            HTML;
    }
}
