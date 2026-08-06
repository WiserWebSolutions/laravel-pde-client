<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialDataElements\AdmRecord;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * Proves the real ADM pipeline - Finder scrapes the listing page, Locator
 * picks the right category/year file, downloads and parses it - actually
 * wires together end to end, same pattern as EnrollmentIntegrationTest.
 */
class FinancialDataElementsIntegrationTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_average_daily_membership_downloads_and_parses_the_real_pipeline(): void
    {
        $workbook = $this->xlsxFixture([
            '2024-25 ADM-WADM' => [
                ['AUN', 'School District', 'County', 'Average Daily Membership', 'Weighted Average Daily Membership', 'Adjusted ADM', 'Adjustment Factor'],
                [124157203, 'Phoenixville Area SD', 'Chester', 3900, 4100, 3950, 1.03],
            ],
        ]);

        Http::fake([
            'pde-client-tests.example/financial-data-elements' => Http::response($this->listingPageHtml()),
            'pde-client-tests.example/average-daily-membership/*' => Http::response(file_get_contents($workbook)),
        ]);

        $record = PDE::district('124157203')->year('2024-2025')->enrollments()->averageDailyMembership()->sole();

        $this->assertInstanceOf(AdmRecord::class, $record);
        $this->assertSame('Phoenixville Area SD', $record->districtName);
        $this->assertSame(3900.0, $record->adm);
        $this->assertSame(4100.0, $record->wadm);
    }

    private function listingPageHtml(): string
    {
        $href = 'https://pde-client-tests.example/average-daily-membership/2024-2025-adm-wadm.xlsx';

        return <<<HTML
            <html>
                <body>
                    <main>
                        <a href="{$href}">2024-2025 ADM-WADM Data</a>
                    </main>
                </body>
            </html>
            HTML;
    }
}
