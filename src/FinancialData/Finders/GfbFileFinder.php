<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Finders;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Parses the General Fund Budget (GFB) data page: a flat list of one xlsx
 * link per school year, e.g. "GFB Data 2024-25".
 */
class GfbFileFinder extends AbstractHtmlFinder
{
    protected function parseDocument(HtmlDocument $document): Collection
    {
        $xlsxLinkXpath = '//a['.self::excludingChrome("substring(@href, string-length(@href) - 4) = '.xlsx'").']';

        return collect($document->query($xlsxLinkXpath))
            ->map(function ($node) use ($document) {
                $label = trim($node->textContent);

                preg_match('/\d{4}-\d{2,4}/', $label, $matches);

                return new RemoteFile(
                    label: $label,
                    url: $document->absoluteUrl($node->getAttribute('href')),
                    category: null,
                    period: $matches[0] ?? null,
                );
            })
            ->values();
    }
}
