<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\AfrDetailWorkbookParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\FundBalanceParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\GfbWorkbookParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\IndebtednessParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Hands the query layer parsed YearTables per (measure, category, year),
 * hiding which workbook the numbers came from, the download-if-missing
 * step, and the parsed-table cache.
 *
 * Budget numbers come from that year's GFB workbook; actual numbers come
 * from the AFR detailed workbooks (four revenue files merged into one
 * revenue table, plus the expenditure detail file).
 */
class FinancialDataRepository
{
    use RemembersParsedRowTables;

    private const ACTUAL_REVENUE_KINDS = ['localrev', 'staterev', 'federalrev', 'otherrev'];

    /** @var array<string, YearTable> per-request memo on top of the Laravel cache */
    private array $memo = [];

    public function __construct(
        private readonly DataFileLocator $locator,
        private readonly GfbWorkbookParser $gfbParser,
        private readonly AfrDetailWorkbookParser $afrParser,
        private readonly FundBalanceParser $fundBalanceParser,
        private readonly IndebtednessParser $indebtednessParser,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableBudgetYears(): array
    {
        return $this->locator->availableGfbYears();
    }

    /**
     * @return list<FiscalYear> newest first - years present in both the
     * revenue and expenditure detail workbooks, so "latest actual year"
     * never resolves to a year only half the data exists for
     */
    public function availableActualYears(): array
    {
        $revenueYears = $this->afrParser->availableYears($this->locator->afrDetailWorkbookPath('localrev'));
        $expenditureYears = $this->afrParser->availableYears($this->locator->afrDetailWorkbookPath('expdetail'));

        $expenditureStarts = array_map(fn (FiscalYear $year) => $year->startYear, $expenditureYears);

        return array_values(array_filter(
            $revenueYears,
            fn (FiscalYear $year) => in_array($year->startYear, $expenditureStarts, true),
        ));
    }

    public function budgetRevenues(FiscalYear $year): YearTable
    {
        return $this->remember("budget:revenue:{$year->long()}", fn () => $this->gfbParser->revenues(
            $this->locator->gfbWorkbookPath($year)
        ));
    }

    public function budgetExpenditures(FiscalYear $year): YearTable
    {
        return $this->remember("budget:expenditure:{$year->long()}", fn () => $this->gfbParser->expenditures(
            $this->locator->gfbWorkbookPath($year)
        ));
    }

    public function actualRevenues(FiscalYear $year): YearTable
    {
        return $this->remember("actual:revenue:{$year->long()}", function () use ($year) {
            $districts = $amounts = $names = [];

            foreach (self::ACTUAL_REVENUE_KINDS as $kind) {
                $table = $this->afrParser->parseYear($this->detailPathWithYear($kind, $year, $this->afrParser->availableYears(...)), $year);

                $districts += $table->districts;
                $names += $table->accountNames;

                foreach ($table->amounts as $aun => $codes) {
                    $amounts[$aun] = ($amounts[$aun] ?? []) + $codes;
                }
            }

            return new YearTable($districts, $amounts, $names);
        });
    }

    public function actualExpenditures(FiscalYear $year): YearTable
    {
        return $this->remember("actual:expenditure:{$year->long()}", fn () => $this->afrParser->parseYear(
            $this->detailPathWithYear('expdetail', $year, $this->afrParser->availableYears(...)),
            $year,
        ));
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableFundBalanceYears(): array
    {
        return $this->fundBalanceParser->availableYears($this->locator->afrDetailWorkbookPath('genfundbalance'));
    }

    public function fundBalance(FiscalYear $year): RowTable
    {
        return $this->rememberRowTable("fundbalance:{$year->long()}", fn () => $this->fundBalanceParser->parseYear(
            $this->detailPathWithYear('genfundbalance', $year, $this->fundBalanceParser->availableYears(...)),
            $year,
        ));
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableIndebtednessYears(): array
    {
        return $this->indebtednessParser->availableYears($this->locator->afrDetailWorkbookPath('soin'));
    }

    public function indebtedness(FiscalYear $year): RowTable
    {
        return $this->rememberRowTable("indebtedness:{$year->long()}", fn () => $this->indebtednessParser->parseYear(
            $this->detailPathWithYear('soin', $year, $this->indebtednessParser->availableYears(...)),
            $year,
        ));
    }

    /**
     * Resolves a detail workbook path, re-downloading once if the local copy
     * predates the requested year - PDE updates these files in place, so a
     * copy cached last summer won't have this year's tab yet.
     *
     * @param  callable(string): list<FiscalYear>  $availableYears
     */
    private function detailPathWithYear(string $kind, FiscalYear $year, callable $availableYears): string
    {
        $path = $this->locator->afrDetailWorkbookPath($kind);

        if ($this->workbookHasYear($path, $year, $availableYears)) {
            return $path;
        }

        $path = $this->locator->afrDetailWorkbookPath($kind, refresh: true);

        if ($this->workbookHasYear($path, $year, $availableYears)) {
            return $path;
        }

        throw DataSetNotFoundException::noneMatched(
            "fiscal year [{$year->short()}] in the AFR '{$kind}' workbook (available: ".
            implode(', ', array_map(fn (FiscalYear $y) => $y->short(), $availableYears($path))).')'
        );
    }

    /**
     * @param  callable(string): list<FiscalYear>  $availableYears
     */
    private function workbookHasYear(string $path, FiscalYear $year, callable $availableYears): bool
    {
        foreach ($availableYears($path) as $available) {
            if ($available->equals($year)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(): YearTable  $produce
     */
    private function remember(string $key, callable $produce): YearTable
    {
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $ttl = (int) config('pde-client.parsed_cache_ttl', 86400);

        if ($ttl <= 0) {
            return $this->memo[$key] = $produce();
        }

        // Stored as plain arrays: object graphs don't survive every cache
        // driver's serialization (see AbstractHtmlFinder for the details).
        $cached = $this->cache->remember(
            'pde-client:parsed:'.$key,
            $ttl,
            fn () => $produce()->toArray(),
        );

        return $this->memo[$key] = YearTable::fromArray($cached);
    }
}
