<?php

namespace WiserWebSolutions\PDEClient\Support;

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
    private function rememberRowTable(string $key, callable $produce): RowTable
    {
        if (isset($this->rowTableMemo[$key])) {
            return $this->rowTableMemo[$key];
        }

        $ttl = (int) config('pde-client.parsed_cache_ttl', 86400);

        if ($ttl <= 0) {
            return $this->rowTableMemo[$key] = $produce();
        }

        $cached = $this->cache->remember(
            'pde-client:parsed:'.$key,
            $ttl,
            fn () => $produce()->toArray(),
        );

        return $this->rowTableMemo[$key] = RowTable::fromArray($cached);
    }
}
