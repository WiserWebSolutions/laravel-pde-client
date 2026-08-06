<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Enums\DebtPhase;
use WiserWebSolutions\PDEClient\Enums\FundType;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\IndebtednessQuery;
use WiserWebSolutions\PDEClient\FinancialData\IndebtednessRecord;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class IndebtednessQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_get_returns_every_row_for_the_most_recent_year(): void
    {
        $records = $this->makeQuery($this->fakeRepository())->district(self::AUN)->get();

        $this->assertCount(3, $records);
        $this->assertContainsOnlyInstancesOf(IndebtednessRecord::class, $records);
    }

    public function test_fund_type_filters_by_enum_or_string(): void
    {
        $byEnum = $this->makeQuery($this->fakeRepository())->district(self::AUN)->fundType(FundType::Proprietary)->get();
        $byString = $this->makeQuery($this->fakeRepository())->district(self::AUN)->fundType('proprietary')->get();

        $this->assertCount(1, $byEnum);
        $this->assertSame(FundType::Proprietary, $byEnum->sole()->fundType);
        $this->assertCount(1, $byString);
        $this->assertSame(FundType::Proprietary, $byString->sole()->fundType);
    }

    public function test_phase_filters_by_enum_or_string(): void
    {
        $records = $this->makeQuery($this->fakeRepository())->district(self::AUN)->phase(DebtPhase::End)->get();

        $this->assertCount(1, $records);
        $this->assertSame(DebtPhase::End, $records->sole()->phase);
    }

    public function test_combining_fund_type_and_phase_narrows_to_a_single_record(): void
    {
        $record = $this->makeQuery($this->fakeRepository())->district(self::AUN)
            ->fundType(FundType::Governmental)
            ->phase(DebtPhase::Beginning)
            ->sole();

        $this->assertSame(1000.0, $record->total);
        $this->assertSame(['Bonds' => 1000.0], $record->categories);
    }

    public function test_sole_throws_when_more_than_one_record_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();
    }

    public function test_unmatched_district_throws(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeQuery($this->fakeRepository(withDistrict: '999999999'))->district(self::AUN)->get();
    }

    private function makeQuery(FinancialDataRepository $repository): IndebtednessQuery
    {
        return new IndebtednessQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): FinancialDataRepository
    {
        $repository = $this->createMock(FinancialDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableIndebtednessYears')->willReturn([FiscalYear::parse('2024-2025')]);

        $repository->method('indebtedness')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            if ($year->long() !== '2024-2025') {
                throw DataSetNotFoundException::noneMatched("year [{$year->long()}]");
            }

            return new RowTable($district, [
                $withDistrict => [
                    ['fund_type' => 'governmental', 'phase' => 'beginning', 'total' => 1000.0, 'categories' => ['Bonds' => 1000.0]],
                    ['fund_type' => 'governmental', 'phase' => 'end', 'total' => 1100.0, 'categories' => ['Bonds' => 1100.0]],
                    ['fund_type' => 'proprietary', 'phase' => 'beginning', 'total' => 200.0, 'categories' => ['Leases' => 200.0]],
                ],
            ]);
        });

        return $repository;
    }
}
