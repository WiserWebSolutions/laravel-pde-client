<?php

namespace WiserWebSolutions\PDEClient\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

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
        $document = new DOMDocument();

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

        return iterator_to_array($nodes, false);
    }

    /**
     * Resolves an href from the document (often root-relative, e.g.
     * "/content/dam/...xlsx") against this document's own URL.
     */
    public function absoluteUrl(string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($this->baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }

        $basePath = rtrim(dirname($parts['path'] ?? '/'), '/');

        return "{$scheme}://{$host}{$basePath}/{$href}";
    }
}
