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
 *     PDE::district('101260303')->year('2024-2025')->get();
 *     PDE::district('101260303')->year('2024-2025')->actual()->revenues()->get();
 *     PDE::district('101260303')->year('2019-2020')->budget()->expenses()->get();
 *
 * Filters accumulate until a terminal call (get/first/sole/sum/total), the
 * same shape as Laravel's query builders; iterating the query directly is
 * equivalent to iterating get(). Only the workbooks the active filters need
 * are downloaded and parsed - a ->budget()->expenses() query never touches
 * the AFR files.
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
     * query resolves to the most recent year the requested measures are
     * published for.
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
        $year = $this->resolveYear();
        $measures = $this->measures ?? [self::MEASURE_BUDGET, self::MEASURE_ACTUAL];
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
        $districtSeen = false;

        foreach ($measures as $measure) {
            foreach ($this->tablesFor($measure, $year) as $tableTag => $table) {
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

        if (! $districtSeen) {
            throw DataSetNotFoundException::noneMatched(
                "district AUN [{$aun}] in the {$year->short()} ".implode('/', $measures).' data'
            );
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
        // below - so ->account('6111')->sole()->parent() still resolves even
        // though 6110 itself was filtered out of the returned collection.
        $records->each(fn (FinancialRecord $record) => $record->attachSiblings($records));

        if ($this->accountCodes === null) {
            return $records;
        }

        return $records
            ->filter(fn (FinancialRecord $record) => in_array($record->accountCode, $this->accountCodes, true))
            ->values();
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
     * Latest published year for the requested measures; when both measures
     * are in play, the newest year present in both - AFR actuals lag GFB
     * budgets by a year or more, so "latest" must not resolve to a year
     * where only the budget exists.
     */
    private function resolveYear(): FiscalYear
    {
        if ($this->year !== null) {
            return $this->year;
        }

        $measures = $this->measures ?? [self::MEASURE_BUDGET, self::MEASURE_ACTUAL];

        $budgetYears = in_array(self::MEASURE_BUDGET, $measures, true)
            ? $this->repository->availableBudgetYears()
            : null;
        $actualYears = in_array(self::MEASURE_ACTUAL, $measures, true)
            ? $this->repository->availableActualYears()
            : null;

        $actualStarts = $actualYears !== null
            ? array_map(fn (FiscalYear $year) => $year->startYear, $actualYears)
            : null;

        $candidates = match (true) {
            $budgetYears !== null && $actualStarts !== null => array_values(array_filter(
                $budgetYears,
                fn (FiscalYear $year) => in_array($year->startYear, $actualStarts, true),
            )),
            $budgetYears !== null => $budgetYears,
            default => $actualYears,
        };

        if ($candidates === []) {
            throw DataSetNotFoundException::noneMatched('any fiscal year published for the requested measures');
        }

        return $candidates[0];
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
                $tables['gfb_revenue_sheet'] = $this->repository->budgetRevenues($year);
            }

            if ($wants(FinancialRecord::CATEGORY_EXPENDITURE)) {
                $tables[FinancialRecord::CATEGORY_EXPENDITURE] = $this->repository->budgetExpenditures($year);
            }
        } else {
            if ($wants(FinancialRecord::CATEGORY_REVENUE)) {
                $tables[FinancialRecord::CATEGORY_REVENUE] = $this->repository->actualRevenues($year);
            }

            if ($wants(FinancialRecord::CATEGORY_EXPENDITURE)) {
                $tables[FinancialRecord::CATEGORY_EXPENDITURE] = $this->repository->actualExpenditures($year);
            }
        }

        return $tables;
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
