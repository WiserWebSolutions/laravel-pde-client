<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use ArrayIterator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enums\DebtPhase;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\FundType;
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\AccountCodeTree;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexQuery;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\RealEstateTaxRateQuery;
use WiserWebSolutions\PDEClient\FinancialDataElements\RealEstateTaxRateRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\SelectedDataQuery;
use WiserWebSolutions\PDEClient\FinancialDataElements\SelectedDataRecord;
use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Fluent query over one district's PDE financial data - budget numbers from
 * the GFB, actual numbers from the AFR, or both merged per account code. This
 * is the hub of the "financials" category: fundBalance(), indebtedness(),
 * realEstateTaxRates()/taxRates(), selectedData(), and actOneIndex() branch
 * into the category's other datasets, carrying over whatever district()/
 * year() is already set.
 *
 *     PDE::district('101260303')->financials()->get();                          // most recent year
 *     PDE::district('101260303')->year('2024-2025')->financials()->get();       // one year
 *     PDE::district('101260303')->year('2024-2025')->financials()->actual()->revenues()->get();
 *     PDE::district('101260303')->year('2019-2020')->financials()->budget()->expenses()->get();
 *     PDE::district('101260303')->financials()->fundBalance()->get();
 *     PDE::district('101260303')->financials()->indebtedness()->get();
 *     PDE::district('101260303')->financials()->realEstateTaxRates()->get();
 *     PDE::district('101260303')->financials()->selectedData()->get();
 *     PDE::district('101260303')->financials()->actOneIndex()->get();
 *     PDE::district('101260303')->financials()->withFundBalance()->withActOneIndex()->get();  // nested into the summary below
 *     PDE::district('101260303')->financials()->withAllDatasets()->get();                      // every sibling dataset nested in
 *
 * Filters accumulate until a terminal call (get/first/sole/sum/total), the
 * same shape as Laravel's query builders; iterating the query directly is
 * equivalent to iterating get(). Only the workbooks the active filters need
 * are downloaded and parsed - a ->budget()->expenses() query never touches
 * the AFR files, and a sibling dataset is only fetched when its withX() was
 * called.
 *
 * get()/first()/sole() don't return flat FinancialRecords directly - they
 * fold every account-code record into one FinancialYearSummary per fiscal
 * year, with that year's underlying FinancialRecords nested in `accounts` for
 * drill-down, and - with withFundBalance()/withIndebtedness()/
 * withRealEstateTaxRates()/withSelectedData()/withActOneIndex()/
 * withAllDatasets() - that year's sibling dataset(s) attached alongside. get()
 * returns that single FinancialYearSummary directly for a query matching
 * exactly one year, or a Collection<int, FinancialYearSummary> for a
 * multi-year query (allYears(), or anything else matching more than one
 * year); first()/sole() always return a single FinancialYearSummary (sole()
 * throwing if more than one year matched). See FinancialYearSummary for its
 * shape, and total() for the flat, un-summarized grand total.
 *
 * Omitting year() returns just the most recent year available for whatever
 * measure(s) are selected - the same convention as every other dataset query
 * (see HasQueryContext). Call allYears()/years()/year('all') for every year
 * available instead. A year missing one measure (e.g. AFR actuals lagging
 * the current GFB budget by a year) simply produces records with that
 * measure null rather than being excluded; a year with neither measure
 * present for the requested district contributes nothing. parent()/
 * children() only ever resolve against records from the *same* fiscal year,
 * even when a query spans many.
 *
 * @implements IteratorAggregate<int, FinancialYearSummary>
 */
class FinancialQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    /** @var list<Measure>|null null = both */
    private ?array $measures = null;

    /** @var list<FinancialCategory>|null null = all categories */
    private ?array $categories = null;

    /** @var list<string>|null null = all account codes */
    private ?array $accountCodes = null;

    private bool $withFundBalance = false;

    private bool $withIndebtedness = false;

    private bool $withRealEstateTaxRates = false;

    private bool $withSelectedData = false;

    private bool $withActOneIndex = false;

    public function __construct(
        private readonly FinancialDataRepository $repository,
        private readonly ChartOfAccounts $chartOfAccounts,
        private readonly Container $container,
        private readonly FinancialDataElementsRepository $financialDataElementsRepository,
        private readonly ActOneIndexRepository $actOneIndexRepository,
    ) {
    }

    /** Year-end general fund balance - a sibling dataset in the financials category. */
    public function fundBalance(): FundBalanceQuery
    {
        return $this->seedSibling($this->container->make(FundBalanceQuery::class));
    }

    /** Statement of Indebtedness - a sibling dataset in the financials category. */
    public function indebtedness(): IndebtednessQuery
    {
        return $this->seedSibling($this->container->make(IndebtednessQuery::class));
    }

    /** Real estate (millage) tax rates - a sibling dataset in the financials category. */
    public function realEstateTaxRates(): RealEstateTaxRateQuery
    {
        return $this->seedSibling($this->container->make(RealEstateTaxRateQuery::class));
    }

    /** Alias for realEstateTaxRates(). */
    public function taxRates(): RealEstateTaxRateQuery
    {
        return $this->realEstateTaxRates();
    }

    /** "Selected Data" (aid ratio, per-pupil expenditure, ...) - a sibling dataset in the financials category. */
    public function selectedData(): SelectedDataQuery
    {
        return $this->seedSibling($this->container->make(SelectedDataQuery::class));
    }

    /** Act 1 adjusted index (max property tax increase without exception/referendum) - a sibling dataset in the financials category. */
    public function actOneIndex(): ActOneIndexQuery
    {
        return $this->seedSibling($this->container->make(ActOneIndexQuery::class));
    }

    /** Nests each year's FundBalanceRecord into that year's FinancialYearSummary. */
    public function withFundBalance(): static
    {
        $this->withFundBalance = true;

        return $this;
    }

    /** Undoes withFundBalance()/withAllDatasets() for this dataset. */
    public function withoutFundBalance(): static
    {
        $this->withFundBalance = false;

        return $this;
    }

    /** Nests each year's IndebtednessRecords into that year's FinancialYearSummary. */
    public function withIndebtedness(): static
    {
        $this->withIndebtedness = true;

        return $this;
    }

    /** Undoes withIndebtedness()/withAllDatasets() for this dataset. */
    public function withoutIndebtedness(): static
    {
        $this->withIndebtedness = false;

        return $this;
    }

    /** Nests each year's RealEstateTaxRateRecords into that year's FinancialYearSummary. */
    public function withRealEstateTaxRates(): static
    {
        $this->withRealEstateTaxRates = true;

        return $this;
    }

    /** Undoes withRealEstateTaxRates()/withAllDatasets() for this dataset. */
    public function withoutRealEstateTaxRates(): static
    {
        $this->withRealEstateTaxRates = false;

        return $this;
    }

    /** Alias for withRealEstateTaxRates(). */
    public function withTaxRates(): static
    {
        return $this->withRealEstateTaxRates();
    }

    /** Alias for withoutRealEstateTaxRates(). */
    public function withoutTaxRates(): static
    {
        return $this->withoutRealEstateTaxRates();
    }

    /** Nests each year's SelectedDataRecord into that year's FinancialYearSummary. */
    public function withSelectedData(): static
    {
        $this->withSelectedData = true;

        return $this;
    }

    /** Undoes withSelectedData()/withAllDatasets() for this dataset. */
    public function withoutSelectedData(): static
    {
        $this->withSelectedData = false;

        return $this;
    }

    /** Nests each year's ActOneIndexRecord into that year's FinancialYearSummary. */
    public function withActOneIndex(): static
    {
        $this->withActOneIndex = true;

        return $this;
    }

    /** Undoes withActOneIndex()/withAllDatasets() for this dataset. */
    public function withoutActOneIndex(): static
    {
        $this->withActOneIndex = false;

        return $this;
    }

    /**
     * Nests every sibling dataset this query knows how to blend into one
     * FinancialYearSummary per year - same as calling withFundBalance(),
     * withIndebtedness(), withRealEstateTaxRates(), withSelectedData(), and
     * withActOneIndex() individually.
     */
    public function withAllDatasets(): static
    {
        $this->withFundBalance = true;
        $this->withIndebtedness = true;
        $this->withRealEstateTaxRates = true;
        $this->withSelectedData = true;
        $this->withActOneIndex = true;

        return $this;
    }

    /** Only budgeted amounts (GFB); AFR files are not touched. */
    public function budget(): static
    {
        $this->measures = [Measure::Budget];

        return $this;
    }

    /** Only actual amounts (AFR); GFB files are not touched. */
    public function actual(): static
    {
        $this->measures = [Measure::Actual];

        return $this;
    }

    /** Revenue accounts (6000-9999, incl. other financing sources). */
    public function revenues(): static
    {
        $this->categories = [FinancialCategory::Revenue];

        return $this;
    }

    /** Expenditure accounts (function codes 1000-5999). */
    public function expenditures(): static
    {
        $this->categories = [FinancialCategory::Expenditure];

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
        $this->categories = [FinancialCategory::FundBalance];

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
     * One FinancialYearSummary per fiscal year in the result, nesting every
     * matched account-code FinancialRecord in `accounts`, plus - for each
     * withX() called - that year's sibling dataset(s). A query that resolves
     * to exactly one fiscal year (an explicit year(), or the most-recent-year
     * default) returns that single FinancialYearSummary directly rather than
     * wrapping it in a Collection; a multi-year query (allYears(), or any
     * other case matching more than one year) returns a
     * Collection<int, FinancialYearSummary> instead.
     *
     * @return FinancialYearSummary|Collection<int, FinancialYearSummary>
     */
    public function get(): FinancialYearSummary|Collection
    {
        $summaries = $this->summaries();

        return $summaries->count() === 1 ? $summaries->first() : $summaries;
    }

    /**
     * Builds every requested account-code record for a single fiscal year,
     * rolling budget and actual up through the chart of accounts
     * independently. $districtSeen/$anyTableChecked are passed by reference
     * so a multi-year query can accumulate them across every year visited
     * without get() re-deriving them from the merged result afterward.
     *
     * @param  list<Measure>  $measures
     * @return Collection<int, FinancialRecord>
     */
    private function recordsForYear(string $aun, FiscalYear $year, array $measures, bool &$districtSeen, bool &$anyTableChecked): Collection
    {
        $wantedCategories = $this->categories ?? [
            FinancialCategory::Revenue,
            FinancialCategory::Expenditure,
            FinancialCategory::FundBalance,
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
                        : FinancialCategory::from($tableTag);

                    $raw[$category->value][$measure->value][$code] = $amount;
                    $names[$category->value][$code] ??= $table->accountNames[$code] ?? null;
                }
            }
        }

        $rows = [];

        foreach ($wantedCategories as $category) {
            if (! isset($raw[$category->value])) {
                continue;
            }

            $tree = $this->chartOfAccounts->treeFor($category);
            $effective = [];

            foreach ($measures as $measure) {
                $effective[$measure->value] = $this->rollUp($raw[$category->value][$measure->value] ?? [], $tree);
            }

            $codes = array_unique(array_merge(...array_values(array_map('array_keys', $effective))));

            foreach ($codes as $code) {
                $rows["{$category->value}|{$code}"] = [
                    'category' => $category,
                    'code' => (string) $code,
                    'name' => $names[$category->value][$code] ?? $tree->nameOf($code),
                    'parentCode' => $tree->exists($code) ? $tree->parentOf($code) : null,
                    'budget' => $effective[Measure::Budget->value][$code] ?? null,
                    'actual' => $effective[Measure::Actual->value][$code] ?? null,
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
            ->sortBy(fn (FinancialRecord $record) => [$record->category->value, $record->accountCode])
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
     * Computes each code's effective amount: a code's own nonzero raw value
     * always wins (a handful of codes - e.g. revenue's 9900 "not listed
     * elsewhere" catch-all - are reported directly even though the chart of
     * accounts also gives them named sub-codes, and those sub-codes are
     * sometimes present in the workbook as explicit zero columns rather than
     * simply absent). Failing that, a code with children takes the sum of
     * its children's effective amounts whenever at least one child has data.
     * A code with neither a nonzero raw value nor any child data falls back
     * to its own raw value (typically zero) so explicitly-reported zeroes
     * still surface. This is what makes budget rollups exist at all for GFB,
     * which normally never publishes a rollup's amount directly (see
     * GfbWorkbookParser) - and it takes precedence over AFR's own rollup
     * columns too, so budget and actual reconcile against the same math
     * instead of two independently-sourced totals.
     *
     * @param  array<string, float>  $raw
     * @return array<string, float>
     */
    private function rollUp(array $raw, AccountCodeTree $tree): array
    {
        $effective = [];

        foreach (array_reverse($tree->codesParentsFirst()) as $code) {
            if (array_key_exists($code, $raw) && (float) $raw[$code] !== 0.0) {
                $effective[$code] = $raw[$code];

                continue;
            }

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

                    continue;
                }
            }

            if (array_key_exists($code, $raw)) {
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

    public function first(): ?FinancialYearSummary
    {
        return $this->summaries()->first();
    }

    /**
     * Exactly one year's summary or a loud failure - for "this district's
     * 2024-25 financials", not "whichever year happened to sort first". Use
     * account() first if you want "the 6111 line" itself - sole()->accounts
     * ->sole() reaches that FinancialRecord.
     */
    public function sole(): FinancialYearSummary
    {
        $summaries = $this->summaries();

        return match (true) {
            $summaries->isEmpty() => throw DataSetNotFoundException::noneMatched($this->filterDescription()),
            $summaries->count() > 1 => throw DataSetNotFoundException::multipleMatched($this->filterDescription(), $summaries->count()),
            default => $summaries->first(),
        };
    }

    /** Sum of amount() across the matched account-code records - flat, un-summarized, regardless of fiscal year. */
    public function total(): float
    {
        return round($this->accountRecords()->sum(fn (FinancialRecord $record) => $record->amount() ?? 0.0), 2);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->summaries()->all());
    }

    /**
     * One FinancialYearSummary per fiscal year matched by accountRecords(),
     * nesting that year's account-code records in `accounts` and - per
     * withX() called - that year's sibling dataset(s) alongside. Always a
     * Collection, even for a single year - get() is what collapses a
     * single-year result down to the bare FinancialYearSummary.
     *
     * @return Collection<int, FinancialYearSummary>
     */
    private function summaries(): Collection
    {
        return $this->accountRecords()
            ->groupBy(fn (FinancialRecord $record) => $record->fiscalYear)
            ->map(fn (Collection $records, string $fiscalYear) => $this->buildSummary($fiscalYear, $records))
            ->values()
            ->sortBy(fn (FinancialYearSummary $summary) => $summary->fiscalYear)
            ->values();
    }

    private function buildSummary(string $fiscalYear, Collection $records): FinancialYearSummary
    {
        /** @var FinancialRecord $first */
        $first = $records->first();
        $year = FiscalYear::parse($fiscalYear);

        return new FinancialYearSummary(
            aun: $first->aun,
            districtName: $first->districtName,
            county: $first->county,
            fiscalYear: $fiscalYear,
            fundBalance: $this->fundBalanceFor($first->aun, $year),
            indebtedness: $this->indebtednessFor($first->aun, $year),
            realEstateTaxRates: $this->realEstateTaxRatesFor($first->aun, $year),
            selectedData: $this->selectedDataFor($first->aun, $year),
            actOneIndex: $this->actOneIndexFor($first->aun, $year),
            accounts: $records->values(),
        );
    }

    private function fundBalanceFor(string $aun, FiscalYear $year): ?FundBalanceRecord
    {
        if (! $this->withFundBalance) {
            return null;
        }

        try {
            $table = $this->repository->fundBalance($year);
        } catch (PDEClientException) {
            return null;
        }

        $district = $table->districts[$aun] ?? null;
        $row = ($table->rows[$aun] ?? [])[0] ?? null;

        if ($district === null || $row === null) {
            return null;
        }

        return new FundBalanceRecord(
            aun: $aun,
            districtName: $district['name'] ?? null,
            county: $district['county'] ?? null,
            fiscalYear: $year->long(),
            committed: $row['committed'],
            assigned: $row['assigned'],
            unassigned: $row['unassigned'],
        );
    }

    /**
     * @return Collection<int, IndebtednessRecord>|null null unless withIndebtedness() was called
     */
    private function indebtednessFor(string $aun, FiscalYear $year): ?Collection
    {
        if (! $this->withIndebtedness) {
            return null;
        }

        try {
            $table = $this->repository->indebtedness($year);
        } catch (PDEClientException) {
            return collect();
        }

        $district = $table->districts[$aun] ?? null;

        if ($district === null) {
            return collect();
        }

        return collect($table->rows[$aun] ?? [])
            ->map(fn (array $row) => new IndebtednessRecord(
                aun: $aun,
                districtName: $district['name'] ?? null,
                county: $district['county'] ?? null,
                fiscalYear: $year->long(),
                fundType: FundType::from($row['fund_type']),
                phase: DebtPhase::from($row['phase']),
                total: $row['total'],
                categories: $row['categories'],
            ))
            ->values();
    }

    /**
     * @return Collection<int, RealEstateTaxRateRecord>|null null unless withRealEstateTaxRates()/withTaxRates() was called
     */
    private function realEstateTaxRatesFor(string $aun, FiscalYear $year): ?Collection
    {
        if (! $this->withRealEstateTaxRates) {
            return null;
        }

        try {
            $table = $this->financialDataElementsRepository->taxRateTable($year);
        } catch (PDEClientException) {
            return collect();
        }

        $district = $table->districts[$aun] ?? null;

        if ($district === null) {
            return collect();
        }

        return collect($table->rows[$aun] ?? [])
            ->map(fn (array $row) => new RealEstateTaxRateRecord(
                aun: $aun,
                districtName: $district['name'] ?? null,
                schoolYear: $year->long(),
                county: $row['county'],
                notes: $row['notes'],
                mills: $row['mills'],
                communityCollegeMills: $row['community_college_mills'],
            ))
            ->values();
    }

    private function selectedDataFor(string $aun, FiscalYear $year): ?SelectedDataRecord
    {
        if (! $this->withSelectedData) {
            return null;
        }

        try {
            $table = $this->financialDataElementsRepository->selectedDataTable($year);
        } catch (PDEClientException) {
            return null;
        }

        $district = $table->districts[$aun] ?? null;
        $row = ($table->rows[$aun] ?? [])[0] ?? null;

        if ($district === null || $row === null) {
            return null;
        }

        return new SelectedDataRecord(
            aun: $aun,
            districtName: $district['name'] ?? null,
            county: $district['county'] ?? null,
            schoolYear: $year->long(),
            aidRatio: $row['aid_ratio'],
            aidRatioRank: $row['aid_ratio_rank'],
            wadm: $row['wadm'],
            adm: $row['adm'],
            admRank: $row['adm_rank'],
            equalizedMills: $row['equalized_mills'],
            equalizedMillsRank: $row['equalized_mills_rank'],
            populationPerSquareMile: $row['population_per_square_mile'],
            populationPerSquareMileRank: $row['population_per_square_mile_rank'],
            instructionExpensePerWadm: $row['instruction_expense_per_wadm'],
            instructionExpensePerWadmRank: $row['instruction_expense_per_wadm_rank'],
            totalExpenditurePerAdm: $row['total_expenditure_per_adm'],
            totalExpenditurePerAdmRank: $row['total_expenditure_per_adm_rank'],
        );
    }

    private function actOneIndexFor(string $aun, FiscalYear $year): ?ActOneIndexRecord
    {
        if (! $this->withActOneIndex) {
            return null;
        }

        try {
            $table = $this->actOneIndexRepository->table($year);
        } catch (PDEClientException) {
            return null;
        }

        $district = $table->districts[$aun] ?? null;
        $row = ($table->rows[$aun] ?? [])[0] ?? null;

        if ($district === null || $row === null) {
            return null;
        }

        return new ActOneIndexRecord(
            aun: $aun,
            districtName: $district['name'] ?? null,
            county: $district['county'] ?? null,
            schoolYear: $year->long(),
            index: $row['index'],
        );
    }

    /**
     * The flat, account-code records every FinancialYearSummary's `accounts`
     * is built from - one row per (fiscal year, category, account code) this
     * query matched, across every year selected.
     *
     * @return Collection<int, FinancialRecord>
     */
    private function accountRecords(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->resolveYears();
        $measures = $this->measures ?? [Measure::Budget, Measure::Actual];

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
                "district AUN [{$aun}] in the ".implode('/', array_map(fn (Measure $m) => $m->value, $measures)).' data'
            );
        }

        if ($this->accountCodes !== null) {
            $records = $records->filter(fn (FinancialRecord $record) => in_array($record->accountCode, $this->accountCodes, true));
        }

        return $records
            ->sortBy(fn (FinancialRecord $record) => [$record->fiscalYear, $record->category->value, $record->accountCode])
            ->values();
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
        $measures = $this->measures ?? [Measure::Budget, Measure::Actual];

        $budgetYears = in_array(Measure::Budget, $measures, true)
            ? $this->repository->availableBudgetYears()
            : [];
        $actualYears = in_array(Measure::Actual, $measures, true)
            ? $this->repository->availableActualYears()
            : [];

        $years = $this->selectYears([...$budgetYears, ...$actualYears]);

        if ($years === []) {
            throw DataSetNotFoundException::noneMatched('any fiscal year published for the requested measures');
        }

        return $years;
    }

    /**
     * @return array<string, YearTable> keyed by category tag
     */
    private function tablesFor(Measure $measure, FiscalYear $year): array
    {
        $wants = fn (FinancialCategory $category) => $this->categories === null
            || in_array($category, $this->categories, true);

        $tables = [];

        if ($measure === Measure::Budget) {
            // The GFB revenue sheet carries fund-balance codes alongside
            // revenue codes, so its rows are classified per code later.
            if ($wants(FinancialCategory::Revenue) || $wants(FinancialCategory::FundBalance)) {
                $this->addTable($tables, 'gfb_revenue_sheet', fn () => $this->repository->budgetRevenues($year));
            }

            if ($wants(FinancialCategory::Expenditure)) {
                $this->addTable($tables, FinancialCategory::Expenditure->value, fn () => $this->repository->budgetExpenditures($year));
            }
        } else {
            if ($wants(FinancialCategory::Revenue)) {
                $this->addTable($tables, FinancialCategory::Revenue->value, fn () => $this->repository->actualRevenues($year));
            }

            if ($wants(FinancialCategory::Expenditure)) {
                $this->addTable($tables, FinancialCategory::Expenditure->value, fn () => $this->repository->actualExpenditures($year));
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

    private function classifyRevenueSheetCode(string $code): FinancialCategory
    {
        return str_starts_with($code, '0')
            ? FinancialCategory::FundBalance
            : FinancialCategory::Revenue;
    }

    private function filterDescription(): string
    {
        $parts = array_filter([
            "district [{$this->aun}]",
            $this->year !== null ? "year [{$this->year->short()}]" : null,
            $this->measures !== null ? implode('+', array_map(fn (Measure $m) => $m->value, $this->measures)) : null,
            $this->categories !== null ? implode('+', array_map(fn (FinancialCategory $c) => $c->value, $this->categories)) : null,
            $this->accountCodes !== null ? 'account(s) ['.implode(', ', $this->accountCodes).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
