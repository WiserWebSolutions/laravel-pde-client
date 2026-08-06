<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialDataElements;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialDataElements\AdmQuery;
use WiserWebSolutions\PDEClient\FinancialDataElements\AdmRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * AdmQuery's own year-selection/filtering logic, tested against a hand-built
 * FinancialDataElementsRepository double rather than real spreadsheets or
 * HTTP - the download/parse pipeline is exercised separately by AdmParserTest
 * and by the integration test.
 */
#[AllowMockObjectsWithoutExpectations]
class AdmQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_the_most_recent_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();

        $this->assertInstanceOf(AdmRecord::class, $result);
        $this->assertSame('2024-2025', $result->schoolYear);
        $this->assertSame('Phoenixville Area SD', $result->districtName);
        $this->assertSame('Chester', $result->county);
        $this->assertSame(200.0, $result->adm);
        $this->assertSame(210.0, $result->wadm);
        $this->assertSame(205.0, $result->adjustedAdm);
        $this->assertSame(5.0, $result->nonresidentAdm);
        $this->assertSame(199.0, $result->totalAdmPde363);
        $this->assertSame(15.0, $result->specialEducationAdm);
        $this->assertSame(1.05, $result->adjustmentFactor);
        $this->assertSame(['ADM Kindergarten' => 50.0], $result->breakdown);
    }

    public function test_all_years_returns_every_published_year_sorted_ascending(): void
    {
        $results = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertSame(['2015-2016', '2024-2025'], $results->pluck('schoolYear')->all());
    }

    public function test_explicit_year_pins_to_that_year_only(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2015-2016')->sole();

        $this->assertSame('2015-2016', $result->schoolYear);
        $this->assertSame(150.0, $result->adm);
        // Pre-2024-25 workbooks don't publish these three metrics.
        $this->assertNull($result->nonresidentAdm);
        $this->assertNull($result->totalAdmPde363);
        $this->assertNull($result->specialEducationAdm);
    }

    public function test_first_returns_the_earliest_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->first();

        $this->assertSame('2015-2016', $result->schoolYear);
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
        $this->assertContainsOnlyInstancesOf(AdmRecord::class, $years);
    }

    private function makeQuery(FinancialDataElementsRepository $repository): AdmQuery
    {
        return new AdmQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): FinancialDataElementsRepository
    {
        $repository = $this->createMock(FinancialDataElementsRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableAdmYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2015-2016'),
        ]);

        $repository->method('admTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new RowTable($district, [
                    $withDistrict => [[
                        'adm' => 200.0,
                        'wadm' => 210.0,
                        'adjusted_adm' => 205.0,
                        'nonresident_adm' => 5.0,
                        'total_adm_pde363' => 199.0,
                        'special_education_adm' => 15.0,
                        'adjustment_factor' => 1.05,
                        'breakdown' => ['ADM Kindergarten' => 50.0],
                    ]],
                ]),
                '2015-2016' => new RowTable($district, [
                    $withDistrict => [[
                        'adm' => 150.0,
                        'wadm' => 155.0,
                        'adjusted_adm' => 152.0,
                        'nonresident_adm' => null,
                        'total_adm_pde363' => null,
                        'special_education_adm' => null,
                        'adjustment_factor' => 1.02,
                        'breakdown' => [],
                    ]],
                ]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
