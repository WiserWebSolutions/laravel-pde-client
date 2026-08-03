<?php

namespace WiserWebSolutions\PDEClient\Finders;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Contracts\FileFinder;
use WiserWebSolutions\PDEClient\Exceptions\PageFetchException;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

/**
 * Fetches one PDE listing page and delegates the actual "what does this page
 * look like" knowledge to a subclass's parseDocument(). Handles the parts
 * every Finder needs regardless of page shape or PDE data domain: fetching,
 * failure handling, and optional result caching so repeated calls don't
 * hammer PDE's site.
 *
 * Any PDE listing page - financial data or otherwise - becomes a new
 * subclass implementing parseDocument().
 */
abstract class AbstractHtmlFinder implements FileFinder
{
    public function __construct(
        protected readonly string $pageUrl,
        protected readonly HttpFactory $http,
        protected readonly ?Cache $cache = null,
        protected readonly int $cacheTtlSeconds = 43200,
    ) {
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    public function find(): Collection
    {
        if ($this->cache === null) {
            return $this->fetchAndParse();
        }

        // Cached as plain arrays, not RemoteFile/Collection objects: several
        // cache stores (e.g. Laravel's database driver) unserialize with
        // object support disabled, which silently turns cached objects into
        // __PHP_Incomplete_Class. Arrays round-trip through every driver.
        $cached = $this->cache->remember(
            $this->cacheKey(),
            $this->cacheTtlSeconds,
            fn () => $this->fetchAndParse()->map(fn (RemoteFile $file) => $file->toArray())->all(),
        );

        return collect($cached)->map(fn (array $attributes) => RemoteFile::fromArray($attributes));
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    protected function fetchAndParse(): Collection
    {
        try {
            $response = $this->http->get($this->pageUrl);
        } catch (ConnectionException $exception) {
            throw PageFetchException::connectionError($this->pageUrl, $exception->getMessage());
        }

        if ($response->failed()) {
            throw PageFetchException::requestFailed($this->pageUrl, $response->status());
        }

        return $this->parseDocument(new HtmlDocument($response->body(), $this->pageUrl));
    }

    protected function cacheKey(): string
    {
        return 'pde-client:'.md5(static::class.'|'.$this->pageUrl);
    }

    /**
     * PA.gov's pages (built on Adobe Experience Manager, judging by the
     * "cmp-"/"aem-Grid" class names) repeat the same chrome on every page: a
     * sidebar <nav>, a "the .gov means it's official" <dialog>, and a global
     * footer. Plain "//h2" or "//a[...]" XPath queries pick up headings/links
     * from that chrome, not just the page's own content, unless excluded.
     *
     * @param  string  $predicate  an XPath predicate to AND this exclusion onto (e.g. an href match); omit for a bare exclusion predicate
     */
    protected static function excludingChrome(string $predicate = ''): string
    {
        $chrome = 'not(ancestor::nav) and not(ancestor::aside) and not(ancestor::dialog) '.
            'and not(ancestor::header) and not(ancestor::footer)';

        return $predicate === '' ? $chrome : "{$predicate} and {$chrome}";
    }

    /**
     * An XPath predicate matching an href ending in `.xlsx` or the legacy
     * `.xls` - SpreadsheetReader reads both (see its class docblock), so a
     * Finder that wants every downloadable workbook, not just the current
     * xlsx-only ones, ANDs this onto excludingChrome():
     *
     *     '//a['.self::excludingChrome(self::spreadsheetHref()).']'
     */
    protected static function spreadsheetHref(): string
    {
        return "(substring(@href, string-length(@href) - 4) = '.xlsx' or ".
            "substring(@href, string-length(@href) - 3) = '.xls')";
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    abstract protected function parseDocument(HtmlDocument $document): Collection;
}
