<?php

namespace WiserWebSolutions\PDEClient\Personnel\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses PDE's professional and support personnel page. Several file
 * families live here; `category` comes from the URL path:
 * - prof-staff-summary: per-year professional staff summary reports (the
 *   dataset the personnel query is built on)
 * - individual: per-year individual staff reports (one row per staff member;
 *   discoverable/downloadable but not modeled - ~35MB each)
 * - support-staff: legacy .xls support personnel counts (discoverable/
 *   downloadable - SpreadsheetReader can read them - but not modeled; one of
 *   these is itself a multi-year range file, so its `period` isn't reliable)
 * - other: attrition/retention, vacancy reports, Act 35, mapping helpers
 */
class PersonnelFileFinder extends AbstractHtmlFinder
{
    private const CATEGORY_BY_PATH_FRAGMENT = [
        '/prof-staff-summary/' => 'staff_summary',
        '/individual/' => 'individual',
        '/support-staff/' => 'support_staff',
    ];

    protected function parseDocument(HtmlDocument $document): Collection
    {
        $linkXpath = '//a['.self::excludingChrome(self::spreadsheetHref()).']';

        return collect($document->query($linkXpath))
            ->map(function ($node) use ($document) {
                $label = trim($node->textContent);
                $url = $document->absoluteUrl($node->getAttribute('href'));

                return new RemoteFile(
                    label: $label,
                    url: $url,
                    category: $this->categorize($url),
                    period: $this->extractPeriod($url),
                );
            })
            ->values();
    }

    private function categorize(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach (self::CATEGORY_BY_PATH_FRAGMENT as $fragment => $category) {
            if (str_contains($path, $fragment)) {
                return $category;
            }
        }

        return 'other';
    }

    /**
     * Staff summary/individual filenames lead with a short-form school year
     * ("2025-26 professional staff summary report.xlsx"). Attrition and
     * retention files span TWO school years ("2023-24-2024-25-attrition...")
     * - the consecutive check on the first pair still holds there, but those
     * live under category 'other' and aren't year-resolved by the locator,
     * so a first-pair period on them is harmless.
     */
    private function extractPeriod(string $url): ?string
    {
        $filename = basename(urldecode((string) parse_url($url, PHP_URL_PATH)));

        // 4-digit end year before 2-digit - see EnrollmentFileFinder for the
        // alternation-order bug this ordering avoids.
        if (! preg_match('/(\d{4})-(\d{4}|\d{2})/', $filename, $matches)) {
            return null;
        }

        $startYear = (int) $matches[1];
        $endYear = strlen($matches[2]) === 2
            ? (int) (substr($matches[1], 0, 2).$matches[2])
            : (int) $matches[2];

        if ($endYear !== $startYear + 1) {
            return null;
        }

        return "{$startYear}-{$endYear}";
    }
}
