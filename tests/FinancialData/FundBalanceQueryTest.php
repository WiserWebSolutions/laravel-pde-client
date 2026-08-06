<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\FundBalanceQuery;
use WiserWebSolutions\PDEClient\FinancialData\FundBalanceRecord;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class FundBalanceQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_the_most_recent_years_records(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->get();

        $this->assertCount(1, $result);
        $record = $result->sole();
        $this->assertInstanceOf(FundBalanceRecord::class, $record);
        $this->assertSame('2024-2025', $record->fiscalYear);
        $this->assertSame('Phoenixville Area SD', $record->districtName);
        $this->assertSame('Chester', $record->county);
        $this->assertSame(100.0, $record->committed);
        $this->assertSame(200.0, $record->assigned);
        $this->assertSame(300.0, $record->unassigned);
        $this->assertSame(600.0, $record->total());
    }

    public function test_all_years_returns_every_published_year_sorted_ascending(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertSame(['2016-2017', '2024-2025'], $result->pluck('fiscalYear')->all());
    }

    public function test_explicit_year_pins_to_that_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2016-2017')->sole();

        $this->assertSame('2016-2017', $result->fiscalYear);
        $this->assertSame(50.0, $result->committed);
    }

    public function test_total_is_null_when_no_component_is_present(): void
    {
        $record = new FundBalanceRecord(
            aun: self::AUN,
            districtName: null,
            county: null,
            fiscalYear: '2024-2025',
            committed: null,
            assigned: null,
            unassigned: null,
        );

        $this->assertNull($record->total());
    }

    public function test_total_sums_only_the_present_components(): void
    {
        $record = new FundBalanceRecord(
            aun: self::AUN,
            districtName: null,
            county: null,
            fiscalYear: '2024-2025',
            committed: 100.0,
            assigned: null,
            unassigned: 50.0,
        );

        $this->assertSame(150.0, $record->total());
    }

    public function test_first_returns_the_earliest_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->first();

        $this->assertSame('2016-2017', $result->fiscalYear);
    }

    public function test_sole_throws_when_more_than_one_year_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->sole();
    }

    public function test_unmatched_district_throws(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('No PDE data file matched');

        $this->makeQuery($this->fakeRepository(withDistrict: '999999999'))->district(self::AUN)->get();
    }

    private function makeQuery(FinancialDataRepository $repository): FundBalanceQuery
    {
        return new FundBalanceQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): FinancialDataRepository
    {
        $repository = $this->createMock(FinancialDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableFundBalanceYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2016-2017'),
        ]);

        $repository->method('fundBalance')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new RowTable($district, [
                    $withDistrict => [['committed' => 100.0, 'assigned' => 200.0, 'unassigned' => 300.0]],
                ]),
                '2016-2017' => new RowTable($district, [
                    $withDistrict => [['committed' => 50.0, 'assigned' => 60.0, 'unassigned' => 70.0]],
                ]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
