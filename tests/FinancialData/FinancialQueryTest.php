<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\FinancialQuery;
use WiserWebSolutions\PDEClient\FinancialData\FinancialRecord;
use WiserWebSolutions\PDEClient\FinancialData\FinancialYearSummary;
use WiserWebSolutions\PDEClient\FinancialData\FundBalanceRecord;
use WiserWebSolutions\PDEClient\FinancialData\IndebtednessRecord;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\SelectedDataRecord;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * FinancialQuery's own merging/rollup/filtering logic, tested against a
 * hand-built FinancialDataRepository double - the download/parse pipeline is
 * exercised separately by the parser tests and FinancialIntegrationTest. Uses
 * the REAL bundled chart-of-accounts resource files (resources/chart-of-
 * accounts/*.json) rather than a hand-rolled tree, so the rollup tests
 * exercise the actual parent/child shape PDE publishes (6000 -> 6100 -> 6110
 * -> 6111/6112, and the 9900 "not listed elsewhere" catch-all with its own
 * named sub-codes 9910/9920/9930/9990).
 */
#[AllowMockObjectsWithoutExpectations]
class FinancialQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_merges_budget_and_actual_by_account_code(): void
    {
        $summary = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->account('6111')->sole();

        $this->assertInstanceOf(FinancialYearSummary::class, $summary);
        $this->assertSame('124157203', $summary->aun);
        $this->assertSame('Phoenixville Area SD', $summary->districtName);
        $this->assertSame('Chester', $summary->county);
        $this->assertSame('2024-2025', $summary->fiscalYear);

        $result = $summary->accounts->sole();
        $this->assertInstanceOf(FinancialRecord::class, $result);
        $this->assertSame(1000.0, $result->budget);
        $this->assertSame(1100.0, $result->actual);
        $this->assertSame(1100.0, $result->amount()); // actual wins when both present
        $this->assertSame(100.0, $result->variance());
    }

    public function test_budget_never_touches_actual_tables(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('actualRevenues');
        $repository->expects($this->never())->method('actualExpenditures');

        $result = $this->makeQuery($repository)
            ->district(self::AUN)->year('2024-2025')->budget()->account('6111')->sole()
            ->accounts->sole();

        $this->assertSame(1000.0, $result->budget);
        $this->assertNull($result->actual);
    }

    public function test_actual_never_touches_budget_tables(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('budgetRevenues');
        $repository->expects($this->never())->method('budgetExpenditures');

        $result = $this->makeQuery($repository)
            ->district(self::AUN)->year('2024-2025')->actual()->account('6111')->sole()
            ->accounts->sole();

        $this->assertNull($result->budget);
        $this->assertSame(1100.0, $result->actual);
    }

    public function test_revenues_never_touches_expenditure_tables(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('budgetExpenditures');
        $repository->expects($this->never())->method('actualExpenditures');

        $this->makeQuery($repository)->district(self::AUN)->year('2024-2025')->revenues()->get();
    }

    public function test_expenditures_never_touches_revenue_tables(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('budgetRevenues');
        $repository->expects($this->never())->method('actualRevenues');

        $this->makeQuery($repository)->district(self::AUN)->year('2024-2025')->expenditures()->get();
    }

    public function test_expenses_is_an_alias_for_expenditures(): void
    {
        $result = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->budget()->expenses()->account('1110')->sole()
            ->accounts->sole();

        $this->assertSame(2000.0, $result->budget);
    }

    public function test_rollup_sums_children_when_parent_not_reported(): void
    {
        // 6110 is never reported directly in either fixture year - it only
        // exists in the result because rollUp() summed 6111 (1000) + 6112
        // (500) through the real chart of accounts.
        $result = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->account('6110')->sole()
            ->accounts->sole();

        $this->assertSame(1500.0, $result->budget);
        $this->assertSame('AD VALOREM TAXES', $result->accountName);
        $this->assertSame('6100', $result->parentCode);
    }

    public function test_rollup_cascades_through_multiple_levels(): void
    {
        $result = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->account('6100')->sole()
            ->accounts->sole();

        $this->assertSame(1500.0, $result->budget); // 6100 -> 6110 -> 6111+6112, same total bubbles up
    }

    /**
     * Regression test for the documented rollUp() fix: revenue's 9900
     * "not listed elsewhere" catch-all is sometimes reported directly even
     * though the chart of accounts also gives it named sub-codes (9910,
     * 9920, ...), and those sub-codes can appear in the workbook as explicit
     * zero columns rather than simply being absent. Before the fix, the
     * sum-of-children branch fired anyway (zero children still count as
     * "present") and overwrote 9900's real reported amount with 0.0.
     */
    public function test_rollup_keeps_a_codes_own_reported_total_when_children_are_explicit_zeros(): void
    {
        $result = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->account('9900')->sole()
            ->accounts->sole();

        $this->assertSame(5000.0, $result->budget);
    }

    public function test_parent_and_children_resolve_across_the_full_record_set_even_when_account_filtered(): void
    {
        // ->account('6111') narrows the *returned* collection to one record,
        // but parent()/children() still resolve because siblings were
        // attached to the full per-year record set before the account()
        // filter was applied.
        $result = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->account('6111')->sole()
            ->accounts->sole();

        $parent = $result->parent();
        $this->assertNotNull($parent);
        $this->assertSame('6110', $parent->accountCode);
        $this->assertSame(1500.0, $parent->budget);

        $siblingViaParent = $parent->children()->firstWhere('accountCode', '6112');
        $this->assertNotNull($siblingViaParent);
        $this->assertSame(500.0, $siblingViaParent->budget);
    }

    public function test_bare_record_without_siblings_has_no_parent_or_children(): void
    {
        $record = new FinancialRecord(
            aun: self::AUN,
            districtName: null,
            county: null,
            fiscalYear: '2024-2025',
            category: \WiserWebSolutions\PDEClient\Enums\FinancialCategory::Revenue,
            accountCode: '6111',
            accountName: 'Current Real Estate Taxes',
            parentCode: '6110',
            budget: 1000.0,
            actual: null,
        );

        $this->assertNull($record->parent());
        $this->assertTrue($record->children()->isEmpty());
        $this->assertTrue($record->isLeaf());
    }

    public function test_account_filter_narrows_to_the_requested_codes(): void
    {
        $summary = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->budget()->account('6111', '1110')->get();

        $this->assertSame(['1110', '6111'], $summary->accounts->pluck('accountCode')->sort()->values()->all());
    }

    public function test_total_sums_amount_across_matched_records(): void
    {
        $total = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->budget()->account('6111', '1110')->total();

        $this->assertSame(3000.0, $total); // 1000 + 2000
    }

    public function test_sole_throws_when_more_than_one_year_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('Expected exactly one');

        $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->allYears()->budget()->account('1110')->sole();
    }

    public function test_first_returns_the_earliest_year_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->allYears()->budget()->account('1110')->first();

        $this->assertSame('2023-2024', $result->fiscalYear);
        $this->assertSame(1800.0, $result->accounts->sole()->budget);
    }

    public function test_unmatched_district_throws(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('No PDE data file matched');

        $this->makeQuery($this->fakeRepository(withDistrict: '999999999'))
            ->district(self::AUN)->year('2024-2025')->get();
    }

    public function test_no_years_available_throws(): void
    {
        $repository = $this->createMock(FinancialDataRepository::class);
        $repository->method('availableBudgetYears')->willReturn([]);
        $repository->method('availableActualYears')->willReturn([]);

        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('any fiscal year published');

        $this->makeQuery($repository)->district(self::AUN)->get();
    }

    public function test_fund_balance_sibling_carries_over_district_and_year(): void
    {
        $repository = $this->fakeRepository();
        $this->app->instance(FinancialDataRepository::class, $repository);

        $repository->method('availableFundBalanceYears')->willReturn([FiscalYear::parse('2024-2025')]);
        $repository->method('fundBalance')->willReturnCallback(function (FiscalYear $year) {
            return match ($year->long()) {
                '2024-2025' => new RowTable(
                    [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
                    [self::AUN => [['committed' => 10.0, 'assigned' => 20.0, 'unassigned' => 30.0]]],
                ),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $result = $this->makeQuery($repository)
            ->district(self::AUN)->year('2024-2025')->fundBalance()->sole();

        $this->assertInstanceOf(FundBalanceRecord::class, $result);
        $this->assertSame('2024-2025', $result->fiscalYear);
        $this->assertSame(60.0, $result->total());
    }

    public function test_indebtedness_sibling_carries_over_district_and_year(): void
    {
        $repository = $this->fakeRepository();
        $this->app->instance(FinancialDataRepository::class, $repository);

        $repository->method('availableIndebtednessYears')->willReturn([FiscalYear::parse('2024-2025')]);
        $repository->method('indebtedness')->willReturnCallback(function (FiscalYear $year) {
            return match ($year->long()) {
                '2024-2025' => new RowTable(
                    [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
                    [self::AUN => [
                        ['fund_type' => 'all', 'phase' => 'end', 'total' => 500.0, 'categories' => []],
                    ]],
                ),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $result = $this->makeQuery($repository)
            ->district(self::AUN)->year('2024-2025')->indebtedness()->sole();

        $this->assertInstanceOf(IndebtednessRecord::class, $result);
        $this->assertSame('2024-2025', $result->fiscalYear);
        $this->assertSame(500.0, $result->total);
    }

    public function test_sibling_datasets_default_to_null_when_not_requested(): void
    {
        $summary = $this->makeQuery($this->fakeRepository())
            ->district(self::AUN)->year('2024-2025')->sole();

        $this->assertNull($summary->fundBalance);
        $this->assertNull($summary->indebtedness);
        $this->assertNull($summary->realEstateTaxRates);
        $this->assertNull($summary->selectedData);
        $this->assertNull($summary->actOneIndex);
    }

    public function test_with_fund_balance_nests_it_into_the_summary(): void
    {
        $repository = $this->fakeRepository();
        $repository->method('fundBalance')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['committed' => 10.0, 'assigned' => 20.0, 'unassigned' => 30.0]]],
        ));

        $summary = $this->makeQuery($repository)
            ->district(self::AUN)->year('2024-2025')->withFundBalance()->sole();

        $this->assertInstanceOf(FundBalanceRecord::class, $summary->fundBalance);
        $this->assertSame(60.0, $summary->fundBalance->total());
        $this->assertNull($summary->selectedData);
    }

    public function test_with_indebtedness_nests_it_into_the_summary(): void
    {
        $repository = $this->fakeRepository();
        $repository->method('indebtedness')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [
                ['fund_type' => 'all', 'phase' => 'end', 'total' => 500.0, 'categories' => []],
                ['fund_type' => 'all', 'phase' => 'beginning', 'total' => 400.0, 'categories' => []],
            ]],
        ));

        $summary = $this->makeQuery($repository)
            ->district(self::AUN)->year('2024-2025')->withIndebtedness()->sole();

        $this->assertCount(2, $summary->indebtedness);
        $this->assertInstanceOf(IndebtednessRecord::class, $summary->indebtedness->first());
    }

    public function test_with_selected_data_nests_it_into_the_summary(): void
    {
        $financialDataElementsRepository = $this->createMock(FinancialDataElementsRepository::class);
        $financialDataElementsRepository->method('selectedDataTable')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [[
                'aid_ratio' => 0.5, 'aid_ratio_rank' => 100.0, 'wadm' => 3000.0, 'adm' => 2900.0, 'adm_rank' => 50.0,
                'equalized_mills' => 20.0, 'equalized_mills_rank' => 30.0,
                'population_per_square_mile' => 400.0, 'population_per_square_mile_rank' => 60.0,
                'instruction_expense_per_wadm' => 8000.0, 'instruction_expense_per_wadm_rank' => 70.0,
                'total_expenditure_per_adm' => 15000.0, 'total_expenditure_per_adm_rank' => 80.0,
            ]]],
        ));

        $summary = $this->makeQuery($this->fakeRepository(), financialDataElementsRepository: $financialDataElementsRepository)
            ->district(self::AUN)->year('2024-2025')->withSelectedData()->sole();

        $this->assertInstanceOf(SelectedDataRecord::class, $summary->selectedData);
        $this->assertSame(8000.0, $summary->selectedData->instructionExpensePerWadm);
    }

    public function test_with_tax_rates_nests_it_into_the_summary(): void
    {
        $financialDataElementsRepository = $this->createMock(FinancialDataElementsRepository::class);
        $financialDataElementsRepository->method('taxRateTable')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['county' => 'Chester', 'notes' => null, 'mills' => 20.5, 'community_college_mills' => null]]],
        ));

        $summary = $this->makeQuery($this->fakeRepository(), financialDataElementsRepository: $financialDataElementsRepository)
            ->district(self::AUN)->year('2024-2025')->withTaxRates()->sole();

        $this->assertCount(1, $summary->realEstateTaxRates);
        $this->assertSame(20.5, $summary->realEstateTaxRates->sole()->mills);
    }

    public function test_with_act_one_index_nests_it_into_the_summary(): void
    {
        $actOneIndexRepository = $this->createMock(ActOneIndexRepository::class);
        $actOneIndexRepository->method('table')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['index' => 0.041]]],
        ));

        $summary = $this->makeQuery($this->fakeRepository(), actOneIndexRepository: $actOneIndexRepository)
            ->district(self::AUN)->year('2024-2025')->withActOneIndex()->sole();

        $this->assertInstanceOf(ActOneIndexRecord::class, $summary->actOneIndex);
        $this->assertSame(0.041, $summary->actOneIndex->index);
    }

    public function test_without_fund_balance_undoes_with_all_datasets_for_that_dataset(): void
    {
        $repository = $this->fakeRepository();
        $repository->method('fundBalance')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['committed' => 10.0, 'assigned' => 20.0, 'unassigned' => 30.0]]],
        ));
        $repository->method('indebtedness')->willThrowException(DataSetNotFoundException::noneMatched('no indebtedness data'));

        $financialDataElementsRepository = $this->createMock(FinancialDataElementsRepository::class);
        $financialDataElementsRepository->method('taxRateTable')->willThrowException(DataSetNotFoundException::noneMatched('no tax rate data'));
        $financialDataElementsRepository->method('selectedDataTable')->willThrowException(DataSetNotFoundException::noneMatched('no selected data'));

        $actOneIndexRepository = $this->createMock(ActOneIndexRepository::class);
        $actOneIndexRepository->method('table')->willThrowException(DataSetNotFoundException::noneMatched('no act one index data'));

        $summary = $this->makeQuery($repository, $financialDataElementsRepository, $actOneIndexRepository)
            ->district(self::AUN)->year('2024-2025')->withAllDatasets()->withoutFundBalance()->sole();

        $this->assertNull($summary->fundBalance);
    }

    public function test_with_all_datasets_nests_every_sibling(): void
    {
        $repository = $this->fakeRepository();
        $repository->method('fundBalance')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['committed' => 10.0, 'assigned' => 20.0, 'unassigned' => 30.0]]],
        ));
        $repository->method('indebtedness')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['fund_type' => 'all', 'phase' => 'end', 'total' => 500.0, 'categories' => []]]],
        ));

        $financialDataElementsRepository = $this->createMock(FinancialDataElementsRepository::class);
        $financialDataElementsRepository->method('taxRateTable')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['county' => 'Chester', 'notes' => null, 'mills' => 20.5, 'community_college_mills' => null]]],
        ));
        $financialDataElementsRepository->method('selectedDataTable')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [[
                'aid_ratio' => 0.5, 'aid_ratio_rank' => null, 'wadm' => null, 'adm' => null, 'adm_rank' => null,
                'equalized_mills' => null, 'equalized_mills_rank' => null,
                'population_per_square_mile' => null, 'population_per_square_mile_rank' => null,
                'instruction_expense_per_wadm' => null, 'instruction_expense_per_wadm_rank' => null,
                'total_expenditure_per_adm' => null, 'total_expenditure_per_adm_rank' => null,
            ]]],
        ));

        $actOneIndexRepository = $this->createMock(ActOneIndexRepository::class);
        $actOneIndexRepository->method('table')->willReturn(new RowTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => [['index' => 0.041]]],
        ));

        $summary = $this->makeQuery($repository, $financialDataElementsRepository, $actOneIndexRepository)
            ->district(self::AUN)->year('2024-2025')->withAllDatasets()->sole();

        $this->assertInstanceOf(FundBalanceRecord::class, $summary->fundBalance);
        $this->assertCount(1, $summary->indebtedness);
        $this->assertCount(1, $summary->realEstateTaxRates);
        $this->assertInstanceOf(SelectedDataRecord::class, $summary->selectedData);
        $this->assertInstanceOf(ActOneIndexRecord::class, $summary->actOneIndex);
    }

    private function makeQuery(
        FinancialDataRepository $repository,
        ?FinancialDataElementsRepository $financialDataElementsRepository = null,
        ?ActOneIndexRepository $actOneIndexRepository = null,
    ): FinancialQuery {
        return new FinancialQuery(
            $repository,
            $this->app->make(ChartOfAccounts::class),
            $this->app,
            $financialDataElementsRepository ?? $this->createMock(FinancialDataElementsRepository::class),
            $actOneIndexRepository ?? $this->createMock(ActOneIndexRepository::class),
        );
    }

    /**
     * A repository double covering two years of budget and actual data.
     * 2024-2025 carries the full fixture (revenue rollup codes, the 9900
     * catch-all regression scenario, fund-balance-in-the-revenue-sheet code
     * 0810, and expenditure function 1110); 2023-2024 is a smaller fixture
     * used only for allYears()/first() tests.
     */
    private function fakeRepository(string $withDistrict = self::AUN): FinancialDataRepository
    {
        $repository = $this->createMock(FinancialDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableBudgetYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2023-2024'),
        ]);
        $repository->method('availableActualYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2023-2024'),
        ]);

        $repository->method('budgetRevenues')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new YearTable($district, [$withDistrict => [
                    '6111' => 1000.0,
                    '6112' => 500.0,
                    '0810' => 200.0,
                    '9900' => 5000.0,
                    '9910' => 0.0,
                    '9920' => 0.0,
                ]], []),
                '2023-2024' => new YearTable($district, [$withDistrict => ['6111' => 900.0]], []),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $repository->method('budgetExpenditures')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new YearTable($district, [$withDistrict => ['1110' => 2000.0]], []),
                '2023-2024' => new YearTable($district, [$withDistrict => ['1110' => 1800.0]], []),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $repository->method('actualRevenues')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new YearTable($district, [$withDistrict => ['6111' => 1100.0, '6112' => 600.0]], [
                    '6111' => 'Current Real Estate Taxes',
                    '6112' => 'Interim Real Estate Taxes',
                ]),
                '2023-2024' => new YearTable($district, [$withDistrict => ['6111' => 950.0]], []),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $repository->method('actualExpenditures')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new YearTable($district, [$withDistrict => ['1110' => 2100.0]], ['1110' => 'Regular Programs']),
                '2023-2024' => new YearTable($district, [$withDistrict => ['1110' => 1850.0]], []),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
