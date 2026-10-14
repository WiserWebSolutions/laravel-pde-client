<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use PHPUnit\Framework\Attributes\DataProvider;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\FinancialQuery;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class CombinedFinancialsTest extends TestCase
{
    private const AUN = '124157203';

    public static function selections(): array
    {
        return [['budget'], ['actual'], ['budgetAndActual']];
    }

    #[DataProvider('selections')]
    public function test_combined_selector_restores_both_measures_and_retains_other_filters(string $initial): void
    {
        $this->registerTables(
            ['2019-2020' => ['6111' => 1000.0, '6112' => 500.0]],
            ['2019-2020' => ['6111' => 1100.0, '6112' => 600.0]],
        );
        $query = PDE::district(self::AUN)->year('2019-2020')->financials()
            ->{$initial}()->revenues()->account('6111')->budgetAndActual();
        $summary = $query->sole();
        $this->assertSame('2019-2020', $summary->fiscalYear);
        $this->assertCount(1, $summary->accounts);
        $account = $summary->accounts->sole();
        $this->assertSame('6111', $account->accountCode);
        $this->assertSame(1000.0, $account->budget);
        $this->assertSame(1100.0, $account->actual);
        $this->assertSame(100.0, $account->variance());
        $this->assertSame(DataAvailability::Available, $account->availability('budget'));
        $this->assertSame(DataAvailability::Available, $account->availability('actual'));
        $this->assertSame($account->toArray(), $query->records()->sole()->toArray());
    }

    public static function missingMeasures(): array
    {
        return [['budget', 'throw'], ['actual', 'throw'], ['budget', 'return_null'], ['actual', 'return_null']];
    }

    #[DataProvider('missingMeasures')]
    public function test_an_unpublished_measure_is_nullable_and_does_not_invent_zero(string $missing, string $behavior): void
    {
        config(['pde-client.missing_data' => $behavior]);
        $values = ['2019-2020' => ['6111' => 0.0]];
        $this->registerTables($missing === 'budget' ? [] : $values, $missing === 'actual' ? [] : $values);
        $summary = $this->financialQuery()->year('2019-2020')->sole();
        $record = $summary->accounts->sole();
        $present = $missing === 'budget' ? 'actual' : 'budget';
        $this->assertNull($record->{$missing});
        $this->assertSame(DataAvailability::NotReported, $record->availability($missing));
        $this->assertFalse($record->hasData($missing));
        $this->assertSame(0.0, $record->{$present});
        $this->assertTrue($record->hasData($present));
        $this->assertNull($record->variance());
        $data = json_decode($summary->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($data['accounts'][0][$missing]);
        $this->assertSame(0, $data['accounts'][0][$present]);
    }

    public function test_history_retains_unmatched_accounts_and_never_pairs_different_years(): void
    {
        $this->registerTables(
            ['2019-2020' => ['6111' => 1000.0], '2020-2021' => ['6111' => 1200.0]],
            ['2018-2019' => ['6111' => 800.0], '2019-2020' => ['6111' => 1100.0, '6112' => 0.0]],
        );
        $summaries = $this->financialQuery()->account('6111', '6112')->allYears()->get();
        $this->assertSame(['2018-2019', '2019-2020', '2020-2021'], $summaries->pluck('fiscalYear')->all());
        $this->assertNull($summaries[0]->accounts->sole()->budget);
        $this->assertSame(800.0, $summaries[0]->accounts->sole()->actual);
        $middle = $summaries[1]->accounts;
        $this->assertCount(2, $middle);
        $this->assertSame(1000.0, $middle->firstWhere('accountCode', '6111')->budget);
        $this->assertSame(1100.0, $middle->firstWhere('accountCode', '6111')->actual);
        $this->assertNull($middle->firstWhere('accountCode', '6112')->budget);
        $this->assertSame(0.0, $middle->firstWhere('accountCode', '6112')->actual);
        $this->assertSame(1200.0, $summaries[2]->accounts->sole()->budget);
        $this->assertNull($summaries[2]->accounts->sole()->actual);
        $this->assertSame('2020-2021', $this->financialQuery()->sole()->fiscalYear, 'The default year uses the union of published measures.');
        $this->expectException(DataSetNotFoundException::class);
        $this->financialQuery()->allYears()->sole();
    }

    public function test_combined_records_preserve_confirmed_noncollection_policy_and_nested_exports(): void
    {
        // Policy-only fixture: this makes no historical report cutoff claim.
        $this->registerTables(
            ['2019-2020' => ['6111' => 1000.0]],
            ['2019-2020' => ['6111' => null]],
            ['2019-2020' => [self::AUN => ['6111' => 'not_collected']]],
        );
        $summary = $this->financialQuery()->year('2019-2020')->sole();
        $record = $summary->accounts->sole();
        $this->assertSame(1000.0, $record->budget);
        $this->assertSame(DataAvailability::NotCollected, $record->availability('actual'));
        $this->assertSame(['accounts' => [['budget' => 1000.0]]], $summary->only('accounts.budget')->toArray());
        foreach (['read', 'json'] as $operation) {
            try {
                $operation === 'read' ? $record->actual : $this->financialQuery()->year('2019-2020')->sole()->toJson();
                $this->fail('Confirmed noncollection must enforce the strict policy.');
            } catch (DataNotCollectedException $exception) {
                $this->assertSame('actual', $exception->field);
                $this->assertSame('2019-2020', $exception->context['year']);
                $this->assertSame(self::AUN, $exception->context['district']);
                $this->assertStringContainsString('actual-2019-2020.xlsx', $exception->context['source']);
            }
        }
        config(['pde-client.missing_data' => 'return_null']);
        $nullable = $this->financialQuery()->year('2019-2020')->sole();
        $this->assertNull($nullable->accounts->sole()->actual);
        $this->assertNull($nullable->accounts->sole()->variance());
        $this->assertNull($nullable->toArray()['accounts'][0]['actual']);
        $this->assertSame(1000.0, $nullable->toArray()['accounts'][0]['budget']);
    }

    private function financialQuery(): FinancialQuery
    {
        return PDE::district(self::AUN)->financials()->budgetAndActual()->revenues()->account('6111');
    }

    private function registerTables(array $budgets, array $actuals, array $actualAvailability = []): void
    {
        $repository = $this->createMock(FinancialDataRepository::class);
        $repository->method('availableBudgetYears')->willReturn(array_map(FiscalYear::parse(...), array_keys($budgets)));
        $repository->method('availableActualYears')->willReturn(array_map(FiscalYear::parse(...), array_keys($actuals)));
        foreach (['budgetRevenues' => $budgets, 'actualRevenues' => $actuals] as $method => $tables) {
            $measure = $method === 'budgetRevenues' ? 'budget' : 'actual';
            $repository->method($method)->willReturnCallback(function (FiscalYear $year) use ($tables, $measure, $actualAvailability) {
                $key = $year->long();
                if (! array_key_exists($key, $tables)) {
                    throw DataSetNotFoundException::noneMatched("{$measure} year [{$key}]");
                }

                return new YearTable(
                    [self::AUN => ['name' => 'Fixture District', 'county' => 'Fixture County']],
                    [self::AUN => $tables[$key]], [],
                    $measure === 'actual' ? ($actualAvailability[$key] ?? []) : [],
                    "https://fixtures.example/{$measure}-{$key}.xlsx",
                );
            });
        }
        $repository->expects($this->never())->method('budgetExpenditures');
        $repository->expects($this->never())->method('actualExpenditures');
        $this->app->instance(FinancialDataRepository::class, $repository);
    }
}
