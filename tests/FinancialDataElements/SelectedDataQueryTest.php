<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\SelectedDataQuery;
use WiserWebSolutions\PDEClient\FinancialDataElements\SelectedDataRecord;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * SelectedDataQuery's own year-selection/filtering logic, tested against a
 * hand-built FinancialDataElementsRepository double - the download/parse
 * pipeline is exercised separately by SelectedDataParserTest and by the
 * integration test.
 */
#[AllowMockObjectsWithoutExpectations]
class SelectedDataQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_the_most_recent_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();

        $this->assertInstanceOf(SelectedDataRecord::class, $result);
        $this->assertSame('2022-2023', $result->schoolYear);
        $this->assertSame('Phoenixville Area SD', $result->districtName);
        $this->assertSame('Chester', $result->county);
        $this->assertSame(0.35, $result->aidRatio);
        $this->assertSame(120.0, $result->aidRatioRank);
        $this->assertSame(1000.0, $result->wadm);
        $this->assertSame(950.0, $result->adm);
        $this->assertSame(110.0, $result->admRank);
        $this->assertSame(18.5, $result->equalizedMills);
        $this->assertSame(75.0, $result->equalizedMillsRank);
        $this->assertSame(2500.0, $result->populationPerSquareMile);
        $this->assertSame(50.0, $result->populationPerSquareMileRank);
        $this->assertSame(9500.0, $result->instructionExpensePerWadm);
        $this->assertSame(200.0, $result->instructionExpensePerWadmRank);
        $this->assertSame(15000.0, $result->totalExpenditurePerAdm);
        $this->assertSame(210.0, $result->totalExpenditurePerAdmRank);
    }

    public function test_all_years_returns_every_published_year_sorted_ascending(): void
    {
        $results = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertSame(['2013-2014', '2022-2023'], $results->pluck('schoolYear')->all());
    }

    public function test_explicit_year_pins_to_that_year_only(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2013-2014')->sole();

        $this->assertSame('2013-2014', $result->schoolYear);
        $this->assertSame(8000.0, $result->instructionExpensePerWadm);
        $this->assertNull($result->populationPerSquareMile);
    }

    public function test_first_returns_the_earliest_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->first();

        $this->assertSame('2013-2014', $result->schoolYear);
    }

    public function test_sole_throws_when_more_than_one_year_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('Expected exactly one');

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->sole();
    }

    public function test_unmatched_district_throws(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('No PDE data file matched');

        $this->makeQuery($this->fakeRepository(withDistrict: '999999999'))->district(self::AUN)->get();
    }

    public function test_get_iterator_yields_every_matched_year(): void
    {
        $years = iterator_to_array($this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears());

        $this->assertCount(2, $years);
        $this->assertContainsOnlyInstancesOf(SelectedDataRecord::class, $years);
    }

    private function makeQuery(FinancialDataElementsRepository $repository): SelectedDataQuery
    {
        return new SelectedDataQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): FinancialDataElementsRepository
    {
        $repository = $this->createMock(FinancialDataElementsRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableSelectedDataYears')->willReturn([
            FiscalYear::parse('2022-2023'),
            FiscalYear::parse('2013-2014'),
        ]);

        $repository->method('selectedDataTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2022-2023' => new RowTable($district, [
                    $withDistrict => [[
                        'aid_ratio' => 0.35,
                        'aid_ratio_rank' => 120.0,
                        'wadm' => 1000.0,
                        'adm' => 950.0,
                        'adm_rank' => 110.0,
                        'equalized_mills' => 18.5,
                        'equalized_mills_rank' => 75.0,
                        'population_per_square_mile' => 2500.0,
                        'population_per_square_mile_rank' => 50.0,
                        'instruction_expense_per_wadm' => 9500.0,
                        'instruction_expense_per_wadm_rank' => 200.0,
                        'total_expenditure_per_adm' => 15000.0,
                        'total_expenditure_per_adm_rank' => 210.0,
                    ]],
                ]),
                '2013-2014' => new RowTable($district, [
                    $withDistrict => [[
                        'aid_ratio' => 0.40,
                        'aid_ratio_rank' => 100.0,
                        'wadm' => 900.0,
                        'adm' => 880.0,
                        'adm_rank' => 90.0,
                        'equalized_mills' => 16.0,
                        'equalized_mills_rank' => 70.0,
                        'population_per_square_mile' => null,
                        'population_per_square_mile_rank' => null,
                        'instruction_expense_per_wadm' => 8000.0,
                        'instruction_expense_per_wadm_rank' => 180.0,
                        'total_expenditure_per_adm' => 12000.0,
                        'total_expenditure_per_adm_rank' => 175.0,
                    ]],
                ]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
