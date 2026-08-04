<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses PDE's "Financial Data Elements" page. Like the enrollment and
 * personnel pages, several unrelated file families live here side by side;
 * `category` comes from each link's URL path:
 * - average_daily_membership: ADM/WADM per district (the dataset the ADM
 *   query is built on)
 * - real_estate_tax_rates: per-district (and, where a district spans more
 *   than one, per-county) millage rates (the dataset the tax rate query is
 *   built on)
 * - aid_ratios, personal_income, selected_data: discoverable/downloadable but
 *   not modeled yet
 */
class FinancialDataElementsFinder extends AbstractHtmlFinder
{
    private const CATEGORY_BY_PATH_FRAGMENT = [
        '/average-daily-membership/' => 'average_daily_membership',
        '/real-estate-tax-rates/' => 'real_estate_tax_rates',
        '/aid-ratios/' => 'aid_ratios',
        '/personal-income/' => 'personal_income',
        '/selected-data/' => 'selected_data',
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
     * Extracts a "2024-2025"-style period from the link label, requiring the
     * two years to be consecutive so a mislabeled or multi-year file (none
     * currently observed on this page, but the same defensive check as every
     * other Finder in this package) doesn't get treated as a real single
     * school-year file.
     */
    private function extractPeriod(string $label): ?string
    {
        // 4-digit end year before 2-digit - see EnrollmentFileFinder for the
        // alternation-order bug this ordering avoids.
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
