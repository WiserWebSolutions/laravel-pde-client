<?php

namespace WiserWebSolutions\PDEClient\Tests\Assessment;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * Proves the real PSSA pipeline - Finder scrapes the listing page, filters
 * to the district-level file, downloads and parses it - actually wires
 * together end to end, same pattern as EnrollmentIntegrationTest.
 */
class AssessmentIntegrationTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_assessments_pssa_downloads_and_parses_the_real_pipeline(): void
    {
        $workbook = $this->xlsxFixture([
            'District Data' => [
                ['AUN', 'County', 'District Name', 'Subject', 'Group', 'Grade', 'Number Scored',
                    'Percent Advanced', 'Percent Proficient', 'Percent Basic', 'Percent Below Basic',
                    'Percent Proficient and above'],
                [124157203, 'Chester', 'Phoenixville Area SD', 'Math', 'All Students', 'Total', 500, 20, 40, 25, 15, 60],
            ],
        ]);

        Http::fake([
            'pde-client-tests.example/assessment' => Http::response($this->listingPageHtml()),
            'pde-client-tests.example/pssa-and-ayp-results/*' => Http::response(file_get_contents($workbook)),
        ]);

        $records = PDE::district('124157203')->year('2024-2025')->assessments()->pssa()->allStudents()->get();

        $this->assertNotEmpty($records);

        $record = $records->first();
        $this->assertSame('Phoenixville Area SD', $record->districtName);
        $this->assertSame(60.0, $record->percentProficientOrAbove);
    }

    private function listingPageHtml(): string
    {
        $href = 'https://pde-client-tests.example/pssa-and-ayp-results/2025-pssa-district-level-data.xlsx';

        return <<<HTML
            <html>
                <body>
                    <main>
                        <a href="{$href}">2025 PSSA District Level Data</a>
                    </main>
                </body>
            </html>
            HTML;
    }
}
