<?php

namespace WiserWebSolutions\PDEClient\Enrollment\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses PDE's enrollment data page. Unlike GFB/AFR, this single page hosts
 * several unrelated datasets side by side (public school enrollment,
 * enrollment projections, English learner counts, private/nonpublic
 * enrollment, homeschool data, low-income percentages) - `category` is
 * derived from each link's URL path rather than a heading, since headings on
 * this page don't line up with dataset boundaries the way AFR's do.
 *
 * Both .xlsx and legacy .xls links are matched (SpreadsheetReader reads
 * both) - PDE's oldest public-enrollment years (2004-05 through 2010-11)
 * are .xls.
 */
class EnrollmentFileFinder extends AbstractHtmlFinder
{
    /**
     * @var array<string, string> URL path fragment => category
     */
    private const CATEGORY_BY_PATH_FRAGMENT = [
        '/enrollment/public-school/' => 'public',
        '/enrollment/enrollment-projections/' => 'projections',
        '/enrollment/private-nonpublic/' => 'private_nonpublic',
        '/english-learners/' => 'english_learners',
        '/home-school-education/' => 'homeschool',
        '/loan-cancellation/' => 'low_income',
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
                    period: $this->extractPeriod($label),
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
     * Extracts a "2024-2025"-style period from the link label, but only for
     * a genuine single school-year file: rejects consolidated multi-year
     * files ("... 2015-2016 through 2024-2025 Consolidated") and mislabeled
     * ranges ("Public School Enrollments 2007-2012") by requiring the two
     * years to be consecutive.
     */
    private function extractPeriod(string $label): ?string
    {
        if (stripos($label, 'through') !== false) {
            return null;
        }

        // \d{4} must come before \d{2} in the alternation: regex alternation
        // takes the first branch that lets the match succeed, not the
        // longest one, so (\d{2}|\d{4}) would match only the first 2 digits
        // of a 4-digit end year ("2026" -> "20") and silently corrupt every
        // year computed from it.
        if (! preg_match('/(\d{4})-(\d{4}|\d{2})/', $label, $matches)) {
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
