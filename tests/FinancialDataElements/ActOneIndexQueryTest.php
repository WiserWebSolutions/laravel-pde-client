<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexQuery;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRepository;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * ActOneIndexQuery's own year-selection/filtering logic, tested against a
 * hand-built ActOneIndexRepository double - the download/parse pipeline is
 * exercised separately by ActOneIndexParserTest.
 */
#[AllowMockObjectsWithoutExpectations]
class ActOneIndexQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_the_most_recent_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();

        $this->assertInstanceOf(ActOneIndexRecord::class, $result);
        $this->assertSame('2024-2025', $result->schoolYear);
        $this->assertSame('Phoenixville Area SD', $result->districtName);
        $this->assertSame('Chester', $result->county);
        $this->assertSame(0.041, $result->index);
    }

    public function test_all_years_returns_every_published_year_sorted_ascending(): void
    {
        $results = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertSame(['2023-2024', '2024-2025'], $results->pluck('schoolYear')->all());
    }

    public function test_explicit_year_pins_to_that_year_only(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2023-2024')->sole();

        $this->assertSame('2023-2024', $result->schoolYear);
        $this->assertSame(0.035, $result->index);
    }

    public function test_first_returns_the_earliest_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->first();

        $this->assertSame('2023-2024', $result->schoolYear);
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
        $this->assertContainsOnlyInstancesOf(ActOneIndexRecord::class, $years);
    }

    private function makeQuery(ActOneIndexRepository $repository): ActOneIndexQuery
    {
        return new ActOneIndexQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): ActOneIndexRepository
    {
        $repository = $this->createMock(ActOneIndexRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2023-2024'),
        ]);

        $repository->method('table')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new RowTable($district, [$withDistrict => [['index' => 0.041]]]),
                '2023-2024' => new RowTable($district, [$withDistrict => [['index' => 0.035]]]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
