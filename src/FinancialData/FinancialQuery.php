<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use ArrayIterator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\AccountCodeTree;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Fluent query over one district's PDE financial data - budget numbers from
 * the GFB, actual numbers from the AFR, or both merged per account code.
 *
 *     PDE::district('101260303')->financial()->get();                          // every year published
 *     PDE::district('101260303')->year('2024-2025')->financial()->get();       // one year
 *     PDE::district('101260303')->year('2024-2025')->financial()->actual()->revenues()->get();
 *     PDE::district('101260303')->year('2019-2020')->financial()->budget()->expenses()->get();
 *
 * Filters accumulate until a terminal call (get/first/sole/sum/total), the
 * same shape as Laravel's query builders; iterating the query directly is
 * equivalent to iterating get(). Only the workbooks the active filters need
 * are downloaded and parsed - a ->budget()->expenses() query never touches
 * the AFR files.
 *
 * Omitting year() returns every year available for whatever measure(s) are
 * selected - the same "all years by default" convention as Enrollment,
 * Assessment, Graduation, and Personnel. A year missing one measure (e.g.
 * AFR actuals lagging the current GFB budget by a year) simply produces
 * records with that measure null rather than being excluded; a year with
 * neither measure present for the requested district contributes nothing.
 * parent()/children() only ever resolve against records from the *same*
 * fiscal year, even when a query spans many.
 *
 * @implements IteratorAggregate<int, FinancialRecord>
 */
class FinancialQuery implements AcceptsQueryContext, IteratorAggregate
{
    private const MEASURE_BUDGET = 'budget';

    private const MEASURE_ACTUAL = 'actual';

    private ?string $aun = null;

    private ?FiscalYear $year = null;

    /** @var list<self::MEASURE_*>|null null = both */
    private ?array $measures = null;

    /** @var list<string>|null null = all categories */
    private ?array $categories = null;

    /** @var list<string>|null null = all account codes */
    private ?array $accountCodes = null;

    public function __construct(
        private readonly FinancialDataRepository $repository,
        private readonly ChartOfAccounts $chartOfAccounts,
    ) {
    }

    /**
     * Selects the LEA by its 9-digit AUN. Called with no argument (or never
     * called), the configured default district applies.
     */
    public function district(?string $aun = null): static
    {
        $aun ??= config('pde-client.default_district');

        if ($aun === null || trim((string) $aun) === '') {
            throw new PDEClientException(
                'No district given and no default configured - set pde-client.default_district (PDE_CLIENT_DEFAULT_AUN) or pass an AUN.'
            );
        }

        $this->aun = trim((string) $aun);

        return $this;
    }

    /**
     * Accepts '2024-25', '2024-2025', or 2024. Without an explicit year the
     * query resolves to every year available for the requested measures.
     */
    public function year(string|int|FiscalYear $year): static
    {
        $this->year = FiscalYear::parse($year);

        return $this;
    }

    /** Only budgeted amounts (GFB); AFR files are not touched. */
    public function budget(): static
    {
        $this->measures = [self::MEASURE_BUDGET];

        return $this;
    }

    /** Only actual amounts (AFR); GFB files are not touched. */
    public function actual(): static
    {
        $this->measures = [self::MEASURE_ACTUAL];

        return $this;
    }

    /** Revenue accounts (6000-9999, incl. other financing sources). */
    public function revenues(): static
    {
        $this->categories = [FinancialRecord::CATEGORY_REVENUE];

        return $this;
    }

    /** Expenditure accounts (function codes 1000-5999). */
    public function expenditures(): static
    {
        $this->categories = [FinancialRecord::CATEGORY_EXPENDITURE];

        return $this;
    }

    /** Alias for expenditures(). */
    public function expenses(): static
    {
        return $this->expenditures();
    }

    /** Fund balance accounts (0810-0850; GFB budget data only). */
    public function fundBalances(): static
    {
        $this->categories = [FinancialRecord::CATEGORY_FUND_BALANCE];

        return $this;
    }

    /**
     * Restrict to specific account code(s), e.g. account('6111') or
     * account('6111', '7110'). Codes are chart-of-accounts dimension codes;
     * expenditures are keyed by 4-digit function code.
     */
    public function account(string ...$codes): static
    {
        $this->accountCodes = array_values(array_map('trim', $codes));

        return $this;
    }

    /**
     * @return Collection<int, FinancialRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->resolveYears();
        $measures = $this->measures ?? [self::MEASURE_BUDGET, self::MEASURE_ACTUAL];

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $yearRecords = $this->recordsForYear($aun, $year, $measures, $districtSeen, $anyTableChecked);
            $records = $records->merge($yearRecords);
        }

        if (! $anyTableChecked) {
            throw DataSetNotFoundException::noneMatched('any fiscal year published for the requested measures');
        }

        if (! $districtSeen) {
            throw DataSetNotFoundException::noneMatched(
                "district AUN [{$aun}] in the ".implode('/', $measures).' data'
            );
        }

        if ($this->accountCodes !== null) {
            $records = $records->filter(fn (FinancialRecord $record) => in_array($record->accountCode, $this->accountCodes, true));
        }

        return $records
            ->sortBy([['fiscalYear', 'asc'], ['category', 'asc'], ['accountCode', 'asc']])
            ->values();
    }

    /**
     * Builds every requested account-code record for a single fiscal year,
     * rolling budget and actual up through the chart of accounts
     * independently. $districtSeen/$anyTableChecked are passed by reference
     * so a multi-year query can accumulate them across every year visited
     * without get() re-deriving them from the merged result afterward.
     *
     * @param  list<self::MEASURE_*>  $measures
     * @return Collection<int, FinancialRecord>
     */
    private function recordsForYear(string $aun, FiscalYear $year, array $measures, bool &$districtSeen, bool &$anyTableChecked): Collection
    {
        $wantedCategories = $this->categories ?? [
            FinancialRecord::CATEGORY_REVENUE,
            FinancialRecord::CATEGORY_EXPENDITURE,
            FinancialRecord::CATEGORY_FUND_BALANCE,
        ];

        // raw[category][measure][code] = amount, as reported by the source -
        // rolled up into effective (parent-aware) amounts below.
        $raw = [];
        $names = [];
        $district = ['name' => null, 'county' => null];

        foreach ($measures as $measure) {
            foreach ($this->tablesFor($measure, $year) as $tableTag => $table) {
                $anyTableChecked = true;

                if (isset($table->districts[$aun])) {
                    $districtSeen = true;
                    $district['name'] ??= $table->districts[$aun]['name'];
                    $district['county'] ??= $table->districts[$aun]['county'];
                }

                foreach ($table->amounts[$aun] ?? [] as $code => $amount) {
                    // The GFB revenue sheet mixes revenue and fund-balance
                    // codes in one table; split them here so each rolls up
                    // against its own (very different) hierarchy below.
                    $category = $tableTag === 'gfb_revenue_sheet'
                        ? $this->classifyRevenueSheetCode($code)
                        : $tableTag;

                    $raw[$category][$measure][$code] = $amount;
                    $names[$category][$code] ??= $table->accountNames[$code] ?? null;
                }
            }
        }

        $rows = [];

        foreach ($wantedCategories as $category) {
            if (! isset($raw[$category])) {
                continue;
            }

            $tree = $this->chartOfAccounts->treeFor($category);
            $effective = [];

            foreach ($measures as $measure) {
                $effective[$measure] = $this->rollUp($raw[$category][$measure] ?? [], $tree);
            }

            $codes = array_unique(array_merge(...array_values(array_map('array_keys', $effective))));

            foreach ($codes as $code) {
                $rows["{$category}|{$code}"] = [
                    'category' => $category,
                    'code' => (string) $code,
                    'name' => $names[$category][$code] ?? $tree->nameOf($code),
                    'parentCode' => $tree->exists($code) ? $tree->parentOf($code) : null,
                    'budget' => $effective[self::MEASURE_BUDGET][$code] ?? null,
                    'actual' => $effective[self::MEASURE_ACTUAL][$code] ?? null,
                ];
            }
        }

        $records = collect($rows)
            ->map(fn (array $row) => new FinancialRecord(
                aun: $aun,
                districtName: $district['name'],
                county: $district['county'],
                fiscalYear: $year->long(),
                category: $row['category'],
                accountCode: $row['code'],
                accountName: $row['name'],
                parentCode: $row['parentCode'],
                budget: $row['budget'],
                actual: $row['actual'],
            ))
            ->sortBy([['category', 'asc'], ['accountCode', 'asc']])
            ->values();

        // Every record can see every other record from this same district/
        // year/measure/category selection, regardless of the account() filter
        // in get() - so ->account('6111')->sole()->parent() still resolves
        // even though 6110 itself was filtered out of the returned
        // collection. Scoped to this year's own records only - siblings
        // never cross a fiscal year boundary, even when get() spans many.
        $records->each(fn (FinancialRecord $record) => $record->attachSiblings($records));

        return $records;
    }

    /**
     * Computes each code's effective amount: a leaf takes the raw reported
     * value; a code with children takes the sum of its children's effective
     * amounts whenever at least one child has data, falling back to its own
     * raw value only if none of its known children do. This is what makes
     * budget rollups exist at all for GFB, which never publishes a rollup's
     * amount directly (see GfbWorkbookParser) - and it takes precedence over
     * AFR's own rollup columns too, so budget and actual reconcile against
     * the same math instead of two independently-sourced totals.
     *
     * @param  array<string, float>  $raw
     * @return array<string, float>
     */
    private function rollUp(array $raw, AccountCodeTree $tree): array
    {
        $effective = [];

        foreach (array_reverse($tree->codesParentsFirst()) as $code) {
            if ($tree->hasChildren($code)) {
                $sum = 0.0;
                $anyChildPresent = false;

                foreach ($tree->childrenOf($code) as $child) {
                    if (array_key_exists($child, $effective)) {
                        $sum += $effective[$child];
                        $anyChildPresent = true;
                    }
                }

                if ($anyChildPresent) {
                    $effective[$code] = round($sum, 2);
                } elseif (array_key_exists($code, $raw)) {
                    $effective[$code] = $raw[$code];
                }
            } elseif (array_key_exists($code, $raw)) {
                $effective[$code] = $raw[$code];
            }
        }

        // Some codes reported by AFR/GFB (particularly older or
        // program-specific sub-codes, e.g. ARRA-era federal stimulus
        // sub-programs under 8700-8799) don't appear in PDE's own published
        // Chart of Accounts manual at all - confirmed by grepping the source
        // text, not just a gap in extraction. Those pass through as
        // parent-less "orphan" records rather than silently vanishing;
        // FinancialRecord::parentCode is null and parent()/children() are
        // simply unavailable for them, same as for any top-level code.
        foreach ($raw as $code => $value) {
            $effective[$code] ??= $value;
        }

        return $effective;
    }

    public function first(): ?FinancialRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - for "the 6111 line", not
     * "whichever line happened to sort first".
     */
    public function sole(): FinancialRecord
    {
        $records = $this->get();

        return match (true) {
            $records->isEmpty() => throw DataSetNotFoundException::noneMatched($this->filterDescription()),
            $records->count() > 1 => throw DataSetNotFoundException::multipleMatched($this->filterDescription(), $records->count()),
            default => $records->first(),
        };
    }

    /** Sum of amount() across the matched records. */
    public function total(): float
    {
        return round($this->get()->sum(fn (FinancialRecord $record) => $record->amount() ?? 0.0), 2);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->get()->all());
    }

    private function resolveAun(): string
    {
        if ($this->aun === null) {
            $this->district();
        }

        return $this->aun;
    }

    /**
     * Every published year for the requested measures, newest first - the
     * union of budget years and actual years when both measures are in
     * play, since a year missing one measure still legitimately has data
     * for the other (see recordsForYear()).
     *
     * @return list<FiscalYear>
     */
    private function resolveYears(): array
    {
        if ($this->year !== null) {
            return [$this->year];
        }

        $measures = $this->measures ?? [self::MEASURE_BUDGET, self::MEASURE_ACTUAL];

        $budgetYears = in_array(self::MEASURE_BUDGET, $measures, true)
            ? $this->repository->availableBudgetYears()
            : [];
        $actualYears = in_array(self::MEASURE_ACTUAL, $measures, true)
            ? $this->repository->availableActualYears()
            : [];

        $byStart = [];

        foreach ([...$budgetYears, ...$actualYears] as $year) {
            $byStart[$year->startYear] = $year;
        }

        if ($byStart === []) {
            throw DataSetNotFoundException::noneMatched('any fiscal year published for the requested measures');
        }

        krsort($byStart);

        return array_values($byStart);
    }

    /**
     * @return array<string, YearTable> keyed by category tag
     */
    private function tablesFor(string $measure, FiscalYear $year): array
    {
        $wants = fn (string $category) => $this->categories === null
            || in_array($category, $this->categories, true);

        $tables = [];

        if ($measure === self::MEASURE_BUDGET) {
            // The GFB revenue sheet carries fund-balance codes alongside
            // revenue codes, so its rows are classified per code later.
            if ($wants(FinancialRecord::CATEGORY_REVENUE) || $wants(FinancialRecord::CATEGORY_FUND_BALANCE)) {
                $this->addTable($tables, 'gfb_revenue_sheet', fn () => $this->repository->budgetRevenues($year));
            }

            if ($wants(FinancialRecord::CATEGORY_EXPENDITURE)) {
                $this->addTable($tables, FinancialRecord::CATEGORY_EXPENDITURE, fn () => $this->repository->budgetExpenditures($year));
            }
        } else {
            if ($wants(FinancialRecord::CATEGORY_REVENUE)) {
                $this->addTable($tables, FinancialRecord::CATEGORY_REVENUE, fn () => $this->repository->actualRevenues($year));
            }

            if ($wants(FinancialRecord::CATEGORY_EXPENDITURE)) {
                $this->addTable($tables, FinancialRecord::CATEGORY_EXPENDITURE, fn () => $this->repository->actualExpenditures($year));
            }
        }

        return $tables;
    }

    /**
     * A requested year might simply not exist for one measure (e.g. AFR
     * actuals haven't been published yet for the current GFB budget year,
     * or vice versa for an old year past the AFR's own history) - not an
     * error for a query spanning multiple years, just nothing to add for
     * that measure/year/category combination.
     *
     * @param  array<string, YearTable>  $tables
     */
    private function addTable(array &$tables, string $key, callable $load): void
    {
        try {
            $tables[$key] = $load();
        } catch (PDEClientException) {
            // nothing published for this measure/year/category - skip it
        }
    }

    private function classifyRevenueSheetCode(string $code): string
    {
        return str_starts_with($code, '0')
            ? FinancialRecord::CATEGORY_FUND_BALANCE
            : FinancialRecord::CATEGORY_REVENUE;
    }

    private function filterDescription(): string
    {
        $parts = array_filter([
            "district [{$this->aun}]",
            $this->year !== null ? "year [{$this->year->short()}]" : null,
            $this->measures !== null ? implode('+', $this->measures) : null,
            $this->categories !== null ? implode('+', $this->categories) : null,
            $this->accountCodes !== null ? 'account(s) ['.implode(', ', $this->accountCodes).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
