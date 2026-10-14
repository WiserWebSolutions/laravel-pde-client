<?php

namespace WiserWebSolutions\PDEClient\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Thin wrapper around DOMDocument/DOMXPath for scraping PDE's listing pages.
 * Deliberately dependency-free (no symfony/dom-crawler) since XPath alone is
 * enough for the "find <a> tags, track which heading they fall under" job
 * these Finders need.
 */
final class HtmlDocument
{
    private readonly DOMXPath $xpath;

    public function __construct(string $html, private readonly string $baseUrl)
    {
        $document = new DOMDocument;

        $previousSetting = libxml_use_internal_errors(true);
        // The XML prolog forces DOMDocument to treat the markup as UTF-8;
        // without it, loadHTML() assumes ISO-8859-1 and mangles multibyte text.
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        $this->xpath = new DOMXPath($document);
    }

    /**
     * @return list<DOMElement>
     */
    public function query(string $expression): array
    {
        $nodes = $this->xpath->query($expression);

        if ($nodes === false) {
            return [];
        }

        return array_values(array_filter(iterator_to_array($nodes, false), static fn ($node) => $node instanceof DOMElement));
    }

    /**
     * Resolves an href from the document (often root-relative, e.g.
     * "/content/dam/...xlsx") against this document's own URL.
     */
    public function absoluteUrl(string $href): string
    {
        return (string) UriResolver::resolve(new Uri($this->baseUrl), new Uri($href));
    }
}
