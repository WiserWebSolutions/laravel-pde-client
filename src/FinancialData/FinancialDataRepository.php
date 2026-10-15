<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\AfrDetailWorkbookParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\FundBalanceParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\GfbWorkbookParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\IndebtednessParser;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RemembersWorkbookYears;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Support\SourceReference;
use WiserWebSolutions\PDEClient\Support\WorkbookFingerprint;

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
    use RemembersWorkbookYears;

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
    ) {}

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableBudgetYears(): array
    {
        return $this->locator->availableGfbYears();
    }

    /**
     * @return list<FiscalYear> newest first - years present in both the
     *                          revenue and expenditure detail workbooks, so "latest actual year"
     *                          never resolves to a year only half the data exists for
     */
    public function availableActualYears(?FinancialCategory $category = null): array
    {
        if ($category === FinancialCategory::Revenue) {
            $path = $this->locator->afrDetailWorkbookPath('localrev');

            return $this->rememberYears($path, fn () => $this->afrParser->availableYears($path), 'afrParser');
        }
        if ($category === FinancialCategory::Expenditure) {
            $path = $this->locator->afrDetailWorkbookPath('expdetail');

            return $this->rememberYears($path, fn () => $this->afrParser->availableYears($path), 'afrParser');
        }

        $revenuePath = $this->locator->afrDetailWorkbookPath('localrev');
        $revenueYears = $this->rememberYears($revenuePath, fn () => $this->afrParser->availableYears($revenuePath), 'afrParser');
        $expenditurePath = $this->locator->afrDetailWorkbookPath('expdetail');
        $expenditureYears = $this->rememberYears($expenditurePath, fn () => $this->afrParser->availableYears($expenditurePath), 'afrParser');

        $expenditureStarts = array_map(fn (FiscalYear $year) => $year->startYear, $expenditureYears);

        return array_values(array_filter(
            $revenueYears,
            fn (FiscalYear $year) => in_array($year->startYear, $expenditureStarts, true),
        ));
    }

    public function budgetRevenues(FiscalYear $year): YearTable
    {
        $path = $this->locator->gfbWorkbookPath($year);

        return $this->remember("budget:revenue:{$year->long()}", fn () => $this->gfbParser->revenues($path), $path);
    }

    public function budgetExpenditures(FiscalYear $year): YearTable
    {
        $path = $this->locator->gfbWorkbookPath($year);

        return $this->remember("budget:expenditure:{$year->long()}", fn () => $this->gfbParser->expenditures($path), $path);
    }

    public function actualRevenues(FiscalYear $year): YearTable
    {
        $paths = array_map(fn ($kind) => $this->detailPathWithYear($kind, $year, $this->afrParser->availableYears(...)), self::ACTUAL_REVENUE_KINDS);

        return $this->remember("actual:revenue:{$year->long()}", function () use ($year, $paths) {
            $districts = $amounts = $names = $availability = $sources = [];
            $provenance = new DataProvenance;
            $amountSources = $districtSources = $nameSources = $codeSources = [];

            foreach ($paths as $path) {
                $table = $this->afrParser->parseYear($path, $year);

                $references = $table->sourceReferences();
                $provenance = $provenance->merge(new DataProvenance($references));
                foreach ($table->districts as $aun => $_) {
                    if (! array_key_exists($aun, $districts)) {
                        $districtSources[$aun] = array_map(fn (SourceReference $source) => $source->id, $table->sourcesForDistrict((string) $aun));
                    }
                }
                foreach ($table->accountNames as $code => $_) {
                    if (! array_key_exists($code, $names)) {
                        $nameSources[$code] = array_map(fn (SourceReference $source) => $source->id, $table->sourcesForName((string) $code));
                    }
                }

                $districts += $table->districts;
                $names += $table->accountNames;
                $sources[] = $table->source;
                foreach ($table->availability as $aun => $states) {
                    $availability[$aun] = ($availability[$aun] ?? []) + $states;
                }

                foreach ($table->amounts as $aun => $codes) {
                    foreach ($codes as $code => $_) {
                        if (! array_key_exists($code, $amounts[$aun] ?? [])) {
                            $ids = array_map(fn (SourceReference $source) => $source->id,
                                $table->sourcesForAmount((string) $aun, (string) $code));
                            $codeSources[$code] ??= $ids;
                            // Most account codes belong to one workbook for every district.
                            if ($ids !== $codeSources[$code]) {
                                $amountSources[$aun][$code] = $ids;
                            }
                        }
                    }
                    $amounts[$aun] = ($amounts[$aun] ?? []) + $codes;
                }
            }

            return new YearTable($districts, $amounts, $names, $availability, implode(', ', array_filter($sources)),
                $provenance, $amountSources, $districtSources, $nameSources, $codeSources);
        }, $paths);
    }

    public function actualExpenditures(FiscalYear $year): YearTable
    {
        $path = $this->detailPathWithYear('expdetail', $year, $this->afrParser->availableYears(...));

        return $this->remember("actual:expenditure:{$year->long()}", fn () => $this->afrParser->parseYear($path, $year), $path);
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableFundBalanceYears(): array
    {
        $path = $this->locator->afrDetailWorkbookPath('genfundbalance');

        return $this->rememberYears($path, fn () => $this->fundBalanceParser->availableYears($path), 'fundBalanceParser');
    }

    public function fundBalance(FiscalYear $year): RowTable
    {
        $path = $this->detailPathWithYear('genfundbalance', $year, $this->fundBalanceParser->availableYears(...));

        return $this->rememberRowTable("fundbalance:{$year->long()}", fn () => $this->fundBalanceParser->parseYear($path, $year), $path);
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableIndebtednessYears(): array
    {
        $path = $this->locator->afrDetailWorkbookPath('soin');

        return $this->rememberYears($path, fn () => $this->indebtednessParser->availableYears($path), 'indebtednessParser');
    }

    public function indebtedness(FiscalYear $year): RowTable
    {
        $path = $this->detailPathWithYear('soin', $year, $this->indebtednessParser->availableYears(...));

        return $this->rememberRowTable("indebtedness:{$year->long()}", fn () => $this->indebtednessParser->parseYear($path, $year), $path);
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
            implode(', ', array_map(fn (FiscalYear $y) => $y->short(), $this->rememberYears($path, fn () => $availableYears($path), (new \ReflectionFunction($availableYears))->getName()))).')'
        );
    }

    /**
     * @param  callable(string): list<FiscalYear>  $availableYears
     */
    private function workbookHasYear(string $path, FiscalYear $year, callable $availableYears): bool
    {
        foreach ($this->rememberYears($path, fn () => $availableYears($path), (new \ReflectionFunction($availableYears))->getName()) as $available) {
            if ($available->equals($year)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(): YearTable  $produce
     */
    private function remember(string $key, callable $produce, string|array $sources = []): YearTable
    {
        $key .= ':'.WorkbookFingerprint::forPaths($sources);
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
            'pde-client:parsed:v'.WorkbookFingerprint::PARSER_VERSION.':'.$key,
            $ttl,
            fn () => $produce()->toArray(),
        );

        return $this->memo[$key] = YearTable::fromArray($cached);
    }
}
