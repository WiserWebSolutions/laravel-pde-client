<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialData\FinancialRecord;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * Proves the real GFB pipeline - Finder scrapes the listing page, DataSource/
 * Locator pick the right file, FilesystemDownloader downloads it, the
 * repository parses it - actually wires together end to end, same pattern
 * as EnrollmentIntegrationTest.
 */
class FinancialDataIntegrationTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_financials_budget_revenues_downloads_and_parses_the_real_gfb_pipeline(): void
    {
        $workbook = $this->xlsxFixture([
            'Rev_BegFB' => [
                ['AUN', 'InstName', 'CountyName', 6111, 6112],
                [124157203, 'Phoenixville Area SD', 'Chester', 1000, 500],
            ],
        ]);

        Http::fake([
            'pde-client-tests.example/gfb' => Http::response($this->listingPageHtml()),
            'pde-client-tests.example/files/*' => Http::response(file_get_contents($workbook)),
        ]);

        $summary = PDE::district('124157203')->year('2024-2025')->financials()->budget()->revenues()->get();

        $this->assertNotEmpty($summary->accounts);

        $account6111 = $summary->accounts->firstWhere('accountCode', '6111');
        $this->assertInstanceOf(FinancialRecord::class, $account6111);
        $this->assertSame(1000.0, $account6111->amount());
    }

    private function listingPageHtml(): string
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
