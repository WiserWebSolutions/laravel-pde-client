<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\Measure;
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

        $summary = PDE::district('124157203')->year('2024-2025')->financials()->budget()->revenues()->get()->sole();

        $this->assertNotEmpty($summary->accounts);

        $account6111 = $summary->accounts->firstWhere('accountCode', '6111');
        $this->assertInstanceOf(FinancialRecord::class, $account6111);
        $this->assertSame(1000.0, $account6111->amount(Measure::Budget));
    }

    public function test_combined_financials_match_gfb_and_afr_workbooks_for_the_same_historical_year(): void
    {
        Http::preventStrayRequests();
        $gfb = $this->xlsxFixture([
            'Rev_BegFB' => [
                ['AUN', 'InstName', 'CountyName', 6111],
                [124157203, 'Fixture District', 'Fixture County', 1000],
                [999999999, 'Other District', 'Other County', 5000],
            ],
            'Exp' => [
                ['AUN', 'InstName', 'CountyName', '1110-100', '1110-200'],
                [124157203, 'Fixture District', 'Fixture County', 1500, 500],
            ],
        ]);
        $responses = [
            'pde-client-tests.example/gfb' => Http::response('<a href="https://pde-client-tests.example/files/2019-2020-gfb-data.xlsx">GFB Data 2019-20</a>'),
            'pde-client-tests.example/files/2019-2020-gfb-data.xlsx' => Http::response(file_get_contents($gfb)),
        ];
        $links = [];
        foreach ([
            'localrev' => ['Local Revenue', '6111', 1100],
            'staterev' => ['State Revenue', '7110', 100],
            'federalrev' => ['Federal Revenue', '8110', 100],
            'otherrev' => ['Other Revenue', '9110', 100],
            'expdetail' => ['Expenditure Detail', '1110', 1900],
        ] as $kind => [$label, $code, $amount]) {
            $url = 'https://pde-client-tests.example/details/'.$kind.'.xlsx';
            $links[] = '<a href="'.$url.'">'.$label.': 2019-20 through 2020-21</a>';
            $afr = $this->xlsxFixture([
                '2019-20' => [
                    ['AUN', 'LEA Name', 'County', 'Fixture '.$code],
                    [124157203, 'Fixture District', 'Fixture County', $amount],
                    [999999999, 'Other District', 'Other County', 9999],
                ],
                '2020-21' => [
                    ['AUN', 'LEA Name', 'County', 'Fixture '.$code],
                    [124157203, 'Fixture District', 'Fixture County', $amount + 500],
                ],
            ]);
            $responses[$url] = Http::response(file_get_contents($afr));
        }
        $responses['pde-client-tests.example/afr'] = Http::response(implode('', $links));
        Http::fake($responses);

        $summary = PDE::district('124157203')->year('2019-2020')->financials()
            ->budgetAndActual()->account('6111', '1110')->sole();
        $this->assertCount(2, $summary->accounts);
        $revenue = $summary->accounts->firstWhere('accountCode', '6111');
        $this->assertSame(1000.0, $revenue->budget);
        $this->assertSame(1100.0, $revenue->actual);
        $this->assertSame(100.0, $revenue->variance());
        $expenditure = $summary->accounts->firstWhere('accountCode', '1110');
        $this->assertSame(2000.0, $expenditure->budget);
        $this->assertSame(1900.0, $expenditure->actual);
        $this->assertSame(-100.0, $expenditure->variance());
        $this->assertSame(DataAvailability::Available, $expenditure->availability('budget'));
        $this->assertSame(DataAvailability::Available, $expenditure->availability('actual'));
        $json = json_decode($summary->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2019-2020', $json['fiscal_year']);
        $this->assertSame(2000, $json['accounts'][0]['budget']);
        $this->assertSame(1900, $json['accounts'][0]['actual']);
        Http::assertSentCount(8); // Two listings, one GFB, and five AFR files.
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
