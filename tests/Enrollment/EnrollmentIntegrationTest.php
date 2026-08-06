<?php

namespace WiserWebSolutions\PDEClient\Tests\Enrollment;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentYearSummary;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * Proves the real pipeline - Finder scrapes the listing page, DataSource/
 * Locator pick the right file, FilesystemDownloader downloads it, the
 * repository parses it - actually wires together end to end, using
 * Http::fake() for both the listing page and the file download and a
 * genuine fixture .xlsx as the "downloaded" content. Every other Enrollment
 * test fakes the repository directly and focuses on EnrollmentQuery's own
 * logic; this one is the seam between this package and PDE's real site.
 */
class EnrollmentIntegrationTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_enrollments_get_downloads_and_parses_the_real_pipeline(): void
    {
        $workbook = $this->xlsxFixture([
            'LEA' => [
                ['AUN', 'LEA Name', 'County', 'K5F', '001'],
                [124157203, 'Phoenixville Area SD', 'Chester', 100, 110],
            ],
        ]);

        Http::fake([
            'pde-client-tests.example/enrollment' => Http::response($this->listingPageHtml()),
            'pde-client-tests.example/enrollment/public-school/*' => Http::response(file_get_contents($workbook)),
        ]);

        $result = PDE::district('124157203')->year('2024-2025')->enrollments()->get();

        $this->assertInstanceOf(EnrollmentYearSummary::class, $result);
        $this->assertSame('2024-2025', $result->schoolYear);
        $this->assertSame('Phoenixville Area SD', $result->districtName);
        $this->assertSame(210.0, $result->enrollmentTotal);
    }

    private function listingPageHtml(): string
    {
        $href = 'https://pde-client-tests.example/enrollment/public-school/2024-2025-public-school-enrollment.xlsx';

        return <<<HTML
            <html>
                <body>
                    <main>
                        <a href="{$href}">2024-2025 Public School Enrollment</a>
                    </main>
                </body>
            </html>
            HTML;
    }
}
