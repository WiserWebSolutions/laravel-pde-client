<?php

namespace WiserWebSolutions\PDEClient\Support;

use WiserWebSolutions\PDEClient\FiscalYear;

trait RemembersWorkbookYears
{
    private array $workbookYearsMemo = [];

    /** @return list<FiscalYear> */
    private function rememberYears(string $path, callable $discover, string $tag): array
    {
        $key = 'pde-client:years:v'.WorkbookFingerprint::PARSER_VERSION.':'.$tag.':'.WorkbookFingerprint::forPaths($path);
        if (isset($this->workbookYearsMemo[$key])) {
            return $this->workbookYearsMemo[$key];
        }
        $produce = fn () => array_map(static fn (FiscalYear $year) => $year->startYear, $discover());
        $ttl = (int) config('pde-client.parsed_cache_ttl', 604800);
        $starts = $ttl <= 0 ? $produce() : $this->cache->remember($key, $ttl, $produce);

        return $this->workbookYearsMemo[$key] = array_map(static fn (int $start) => FiscalYear::parse($start), $starts);
    }
}
