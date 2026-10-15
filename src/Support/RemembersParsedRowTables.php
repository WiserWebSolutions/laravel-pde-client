<?php

namespace WiserWebSolutions\PDEClient\Support;

use WiserWebSolutions\PDEClient\AgentData\SourceReference;

/**
 * The parsed-workbook caching pattern shared by every RowTable-based
 * repository (assessment, graduation, personnel): per-request memo in front
 * of the Laravel cache, tables stored as plain arrays because object graphs
 * don't survive every cache driver's serialization (see AbstractHtmlFinder
 * for the incident that established that rule).
 *
 * The using class must have a `$cache` property holding an
 * Illuminate\Contracts\Cache\Repository.
 */
trait RemembersParsedRowTables
{
    /** @var array<string, RowTable> */
    private array $rowTableMemo = [];

    /**
     * @param  callable(): RowTable  $produce
     */
    private function rememberRowTable(string $key, callable $produce, string|array $sources = []): RowTable
    {
        $key .= ':'.WorkbookFingerprint::forPaths($sources);
        if (isset($this->rowTableMemo[$key])) {
            return $this->rowTableMemo[$key];
        }

        $ttl = (int) config('pde-client.parsed_cache_ttl', 86400);

        if ($ttl <= 0) {
            return $this->rowTableMemo[$key] = $produce()->withSources(SourceReference::forPaths($sources));
        }

        $cached = $this->cache->remember(
            'pde-client:parsed:v'.WorkbookFingerprint::PARSER_VERSION.':'.$key,
            $ttl,
            fn () => $produce()->withSources(SourceReference::forPaths($sources))->toArray(),
        );

        return $this->rowTableMemo[$key] = RowTable::fromArray($cached);
    }
}
