<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses the AFR (Annual Financial Report) detailed-data page. Unlike GFB,
 * files here are grouped under headings ("Revenues", "Expenditures",
 * "Miscellaneous") and each file typically spans a multi-year range rather
 * than one file per year. A handful of standalone full-year workbooks are
 * listed before the first heading; those are tagged with DEFAULT_CATEGORY.
 *
 * Grouping is derived by walking headings and xlsx links together in
 * document order and remembering the most recently seen heading - this page
 * has no other structural marker (class names, data attributes) tying a link
 * to its section.
 */
class AfrFileFinder extends AbstractHtmlFinder
{
    public const DEFAULT_CATEGORY = 'Full AFR Data';

    protected function parseDocument(HtmlDocument $document): Collection
    {
        $headingXpath = '//h2['.self::excludingChrome().'] | //h3['.self::excludingChrome().']';
        $xlsxLinkXpath = '//a['.self::excludingChrome("substring(@href, string-length(@href) - 4) = '.xlsx'").']';

        $files = collect();
        $category = self::DEFAULT_CATEGORY;

        foreach ($document->query("{$headingXpath} | {$xlsxLinkXpath}") as $node) {
            if (in_array($node->nodeName, ['h2', 'h3'], true)) {
                $category = trim($node->textContent);

                continue;
            }

            $label = trim($node->textContent);

            $files->push(new RemoteFile(
                label: $label,
                url: $document->absoluteUrl($node->getAttribute('href')),
                category: $category,
                period: $this->extractPeriod($label),
            ));
        }

        return $files->values();
    }

    /**
     * Most labels read "Name: <period>" (e.g. "Local Revenue: 2015-16 to
     * 2024-25"); a few standalone files have the year embedded without a
     * colon (e.g. "2024-25 AFR data"), so fall back to a bare year-range scan.
     */
    private function extractPeriod(string $label): ?string
    {
        if (str_contains($label, ':')) {
            $afterColon = trim(substr($label, strpos($label, ':') + 1));

            if ($afterColon !== '') {
                return $afterColon;
            }
        }

        if (preg_match('/\d{4}-\d{2,4}(?:\s+to\s+\d{4}-\d{2,4})?/', $label, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
