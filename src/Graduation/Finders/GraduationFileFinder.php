<?php

namespace WiserWebSolutions\PDEClient\Graduation\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses PDE's high school graduation page: cohort graduation rate
 * workbooks (one per school year per 4/5/6-year cohort span) and dropout
 * summaries (one per school year).
 *
 * Both .xlsx and legacy .xls links are matched (SpreadsheetReader reads
 * both) - dropout files before 2012-13 are .xls.
 */
class GraduationFileFinder extends AbstractHtmlFinder
{
    private const CATEGORY_BY_PATH_FRAGMENT = [
        '/cohort-graduation-rates/' => 'cohort',
        '/dropouts/' => 'dropouts',
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
     * Filenames lead with the school year ("2024-2025 pennsylvania 4-year
     * cohort graduation rates.xlsx", "dropouts public by school
     * 2024-25.xlsx") - a consecutive-year pair anywhere in the filename.
     */
    private function extractPeriod(string $url): ?string
    {
        $filename = basename(urldecode((string) parse_url($url, PHP_URL_PATH)));

        // 4-digit end year takes priority in the alternation - see
        // EnrollmentFileFinder for the alternation-order bug this avoids.
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
