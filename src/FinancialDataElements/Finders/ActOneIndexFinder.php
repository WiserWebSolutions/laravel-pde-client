<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses PDE's "Act 1 Index" page. Every file lives under the same
 * .../act1index/ directory, so `category` comes from each link's filename:
 * - adjusted_index_history: one workbook, one row per district, one column
 *   per school year (2015-16 onward) - the per-district cap this package
 *   models, since it already bakes in the base index x (0.75 + district's
 *   MV/PI aid ratio) adjustment PDE applies above a 0.4000 aid ratio.
 * - adjusted_index_current: the same data as its own single-year listing,
 *   superseded once that year is folded into the history workbook above.
 * - base_index_history: PDF, a single statewide percentage per year with no
 *   district dimension to query by - discoverable but not modeled.
 */
class ActOneIndexFinder extends AbstractHtmlFinder
{
    protected function parseDocument(HtmlDocument $document): Collection
    {
        $linkXpath = '//a['.self::excludingChrome("contains(@href, '/act1index/')").']';

        return collect($document->query($linkXpath))
            ->map(function ($node) use ($document) {
                $url = $document->absoluteUrl($node->getAttribute('href'));

                return new RemoteFile(
                    label: trim($node->textContent),
                    url: $url,
                    category: $this->categorize($url),
                );
            })
            ->values();
    }

    private function categorize(string $url): string
    {
        $filename = strtolower((string) parse_url($url, PHP_URL_PATH));

        return match (true) {
            str_contains($filename, 'adjindexhistory') => 'adjusted_index_history',
            str_contains($filename, 'adjindex') => 'adjusted_index_current',
            str_contains($filename, 'baseindexhistory') => 'base_index_history',
            default => 'other',
        };
    }
}
