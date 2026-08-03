<?php

namespace WiserWebSolutions\PDEClient\Assessment\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses PDE's assessment reporting page: PSSA and Keystone result
 * workbooks, one per exam administration year, each published at three
 * aggregation levels (state, district, school).
 *
 * `category` comes from the URL path (pssa | keystone); `period` is the
 * SCHOOL YEAR the administration belongs to - PDE names these files by the
 * calendar year of the spring testing window ("2025 PSSA..." was taken in
 * spring of school year 2024-25), so exam year Y maps to period
 * "(Y-1)-Y", keeping ->year('2024-2025') consistent with every other
 * dataset in this package.
 */
class AssessmentFileFinder extends AbstractHtmlFinder
{
    private const CATEGORY_BY_PATH_FRAGMENT = [
        '/pssa-and-ayp-results/' => 'pssa',
        '/keystones/' => 'keystone',
    ];

    protected function parseDocument(HtmlDocument $document): Collection
    {
        $xlsxLinkXpath = '//a['.self::excludingChrome("substring(@href, string-length(@href) - 4) = '.xlsx'").']';

        return collect($document->query($xlsxLinkXpath))
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
     * Filenames lead with the 4-digit exam administration year ("2025-pssa-
     * district-level-data.xlsx", "2015 pssa district data.xlsx"); the URL is
     * used rather than the label because labels on this page occasionally
     * carry extra prose.
     */
    private function extractPeriod(string $url): ?string
    {
        $filename = basename(urldecode((string) parse_url($url, PHP_URL_PATH)));

        if (! preg_match('/^(\d{4})\D/', $filename, $matches)) {
            return null;
        }

        $examYear = (int) $matches[1];

        return ($examYear - 1).'-'.$examYear;
    }
}
