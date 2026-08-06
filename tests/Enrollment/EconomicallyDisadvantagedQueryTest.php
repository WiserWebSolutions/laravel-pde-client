<?php

namespace WiserWebSolutions\PDEClient\Tests\Enrollment;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Enrollment\EconomicallyDisadvantagedQuery;
use WiserWebSolutions\PDEClient\Enrollment\EconomicallyDisadvantagedRecord;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EconomicallyDisadvantagedQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_the_most_recent_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();

        $this->assertInstanceOf(EconomicallyDisadvantagedRecord::class, $result);
        $this->assertSame('2024-2025', $result->schoolYear);
        $this->assertSame(500.0, $result->economicallyDisadvantagedCount);
        $this->assertSame(1000.0, $result->enrollment);
        $this->assertSame(50.0, $result->percentEconomicallyDisadvantaged);
    }

    public function test_all_years_returns_every_published_year_sorted_ascending(): void
    {
        $results = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertSame(['2016-2017', '2024-2025'], $results->pluck('schoolYear')->all());
    }

    public function test_explicit_year_pins_to_that_year_only(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2016-2017')->sole();

        $this->assertSame('2016-2017', $result->schoolYear);
        $this->assertSame(200.0, $result->economicallyDisadvantagedCount);
    }

    public function test_first_returns_the_earliest_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->first();

        $this->assertSame('2016-2017', $result->schoolYear);
    }

    public function test_sole_throws_when_more_than_one_year_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->sole();
    }

    public function test_unmatched_district_throws(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeQuery($this->fakeRepository(withDistrict: '999999999'))->district(self::AUN)->get();
    }

    private function makeQuery(EnrollmentDataRepository $repository): EconomicallyDisadvantagedQuery
    {
        return new EconomicallyDisadvantagedQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): EnrollmentDataRepository
    {
        $repository = $this->createMock(EnrollmentDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'lea_type' => 'SD', 'county' => 'Chester']];

        $repository->method('availableEconomicallyDisadvantagedYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2016-2017'),
        ]);

        $repository->method('economicallyDisadvantagedTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new RowTable($district, [
                    $withDistrict => [['economically_disadvantaged' => 500.0, 'enrollment' => 1000.0, 'percent' => 50.0]],
                ]),
                '2016-2017' => new RowTable($district, [
                    $withDistrict => [['economically_disadvantaged' => 200.0, 'enrollment' => 800.0, 'percent' => 25.0]],
                ]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
