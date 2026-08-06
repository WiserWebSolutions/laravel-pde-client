<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\RealEstateTaxRateQuery;
use WiserWebSolutions\PDEClient\FinancialDataElements\RealEstateTaxRateRecord;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * RealEstateTaxRateQuery's own year-selection/filtering logic, tested against
 * a hand-built FinancialDataElementsRepository double - the download/parse
 * pipeline is exercised separately by RealEstateTaxRateParserTest and by the
 * integration test.
 */
#[AllowMockObjectsWithoutExpectations]
class RealEstateTaxRateQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_every_county_line_for_the_most_recent_year(): void
    {
        $results = $this->makeQuery($this->fakeRepository())->district(self::AUN)->get();

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(2, $results);
        $this->assertSame(['2024-2025', '2024-2025'], $results->pluck('schoolYear')->all());
        $this->assertSame(['Chester', 'Montgomery'], $results->pluck('county')->all());

        $chester = $results->firstWhere('county', 'Chester');
        $this->assertSame('Phoenixville Area SD', $chester->districtName);
        $this->assertSame(20.5, $chester->mills);
        $this->assertSame(1.2, $chester->communityCollegeMills);
        $this->assertNull($chester->notes);

        $montgomery = $results->firstWhere('county', 'Montgomery');
        $this->assertSame(21.0, $montgomery->mills);
    }

    public function test_sole_throws_when_the_district_spans_more_than_one_county(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('Expected exactly one');

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();
    }

    public function test_all_years_returns_every_published_year_sorted_by_year_then_county(): void
    {
        $results = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertSame(
            ['2016-2017', '2024-2025', '2024-2025'],
            $results->pluck('schoolYear')->all()
        );
    }

    public function test_explicit_year_pins_to_that_year_only(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2016-2017')->sole();

        $this->assertSame('2016-2017', $result->schoolYear);
        $this->assertSame('Chester', $result->county);
        $this->assertSame(18.0, $result->mills);
        $this->assertNull($result->communityCollegeMills);
    }

    public function test_first_returns_the_earliest_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->first();

        $this->assertSame('2016-2017', $result->schoolYear);
    }

    public function test_unmatched_district_throws(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('No PDE data file matched');

        $this->makeQuery($this->fakeRepository(withDistrict: '999999999'))->district(self::AUN)->get();
    }

    public function test_get_iterator_yields_every_matched_record(): void
    {
        $records = iterator_to_array($this->makeQuery($this->fakeRepository())->district(self::AUN));

        $this->assertCount(2, $records);
        $this->assertContainsOnlyInstancesOf(RealEstateTaxRateRecord::class, $records);
    }

    private function makeQuery(FinancialDataElementsRepository $repository): RealEstateTaxRateQuery
    {
        return new RealEstateTaxRateQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): FinancialDataElementsRepository
    {
        $repository = $this->createMock(FinancialDataElementsRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD']];

        $repository->method('availableTaxRateYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2016-2017'),
        ]);

        $repository->method('taxRateTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new RowTable($district, [
                    $withDistrict => [
                        ['county' => 'Chester', 'notes' => null, 'mills' => 20.5, 'community_college_mills' => 1.2],
                        ['county' => 'Montgomery', 'notes' => null, 'mills' => 21.0, 'community_college_mills' => null],
                    ],
                ]),
                '2016-2017' => new RowTable($district, [
                    $withDistrict => [
                        ['county' => 'Chester', 'notes' => null, 'mills' => 18.0, 'community_college_mills' => null],
                    ],
                ]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
