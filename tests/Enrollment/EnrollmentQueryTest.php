<?php

namespace WiserWebSolutions\PDEClient\Tests\Enrollment;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Enrollment\EconomicallyDisadvantagedRecord;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentQuery;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentRecord;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentYearSummary;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * EnrollmentQuery's own merging/collapsing/filtering logic, tested against a
 * hand-built EnrollmentDataRepository double rather than real spreadsheets or
 * HTTP - the download/parse pipeline is exercised separately by the parser
 * tests and by EnrollmentFileLocator/Finder tests, so these can focus purely
 * on what EnrollmentQuery does with whatever tables the repository hands it.
 */
#[AllowMockObjectsWithoutExpectations]
class EnrollmentQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_a_single_summary_for_the_most_recent_actual_year(): void
    {
        $query = $this->makeQuery($this->fakeRepository());

        $result = $query->district(self::AUN)->get();

        $this->assertInstanceOf(EnrollmentYearSummary::class, $result);
        $this->assertSame('2024-2025', $result->schoolYear);
        $this->assertSame(210.0, $result->enrollmentTotal);
        $this->assertNull($result->projectedEnrollmentTotal);
        $this->assertNull($result->englishLearnersTotal);
        $this->assertNull($result->economicallyDisadvantagedTotal);
        $this->assertNull($result->economicallyDisadvantaged);
    }

    public function test_default_query_never_touches_projection_or_el_tables(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('projectionTable');
        $repository->expects($this->never())->method('elTable');
        $repository->expects($this->never())->method('economicallyDisadvantagedTable');

        $this->makeQuery($repository)->district(self::AUN)->get();
    }

    public function test_all_years_returns_every_actual_year_as_a_collection_sorted_ascending(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(['2023-2024', '2024-2025'], $result->pluck('schoolYear')->all());
    }

    public function test_with_projections_includes_actual_and_projected_together(): void
    {
        // The union of actual + projected years makes 2025-2026 (a
        // projection-only year) the new "most recent", so this also proves
        // withProjections() reaches years bare get() would never see.
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->withProjections()->get();

        $this->assertSame('2025-2026', $result->schoolYear);
        $this->assertNull($result->enrollmentTotal);
        $this->assertSame(240.0, $result->projectedEnrollmentTotal);
    }

    public function test_only_projections_excludes_actual_data_entirely(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('publicTable');

        $result = $this->makeQuery($repository)->district(self::AUN)->onlyProjections()->get();

        $this->assertSame('2025-2026', $result->schoolYear);
        $this->assertSame(240.0, $result->projectedEnrollmentTotal);
        $this->assertNull($result->enrollmentTotal);
    }

    public function test_without_projections_undoes_with_projections(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('projectionTable');

        $result = $this->makeQuery($repository)->district(self::AUN)
            ->withProjections()
            ->withoutProjections()
            ->get();

        $this->assertSame('2024-2025', $result->schoolYear);
        $this->assertNull($result->projectedEnrollmentTotal);
    }

    public function test_with_english_learners_merges_into_the_same_grade_record_as_general_enrollment(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->withEnglishLearners()->get();

        $this->assertSame(210.0, $result->enrollmentTotal);
        $this->assertSame(20.0, $result->englishLearnersTotal);

        // The whole point: one EnrollmentRecord per grade, not two - EL and
        // general enrollment merged onto the same K row instead of a
        // duplicate record that happens to share a grade.
        $this->assertCount(2, $result->grades);

        $k = $result->grades->firstWhere('grade', 'K');
        $this->assertSame(100.0, $k->count);
        $this->assertSame(20.0, $k->englishLearnersCount);
        $this->assertNull($k->projectedCount);

        $first = $result->grades->firstWhere('grade', '1');
        $this->assertSame(110.0, $first->count);
        $this->assertNull($first->englishLearnersCount);
    }

    public function test_only_english_learners_excludes_general_enrollment_entirely(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('publicTable');
        $repository->expects($this->never())->method('projectionTable');

        $result = $this->makeQuery($repository)->district(self::AUN)->onlyEnglishLearners()->get();

        $this->assertSame(20.0, $result->englishLearnersTotal);
        $this->assertNull($result->enrollmentTotal);
    }

    public function test_with_all_datasets_implies_economically_disadvantaged(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->withAllDatasets()->get();

        $this->assertSame(210.0, $result->enrollmentTotal);
        $this->assertSame(20.0, $result->englishLearnersTotal);
        $this->assertSame(500.0, $result->economicallyDisadvantagedTotal);
        $this->assertInstanceOf(EconomicallyDisadvantagedRecord::class, $result->economicallyDisadvantaged);
        $this->assertSame(50.0, $result->economicallyDisadvantaged->percentEconomicallyDisadvantaged);
    }

    public function test_with_economically_disadvantaged_alone_leaves_other_datasets_untouched(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('elTable');

        $result = $this->makeQuery($repository)->district(self::AUN)->withEconomicallyDisadvantaged()->get();

        $this->assertSame(210.0, $result->enrollmentTotal);
        $this->assertNull($result->englishLearnersTotal);
        $this->assertSame(500.0, $result->economicallyDisadvantagedTotal);
    }

    public function test_grade_filter_narrows_totals_and_grades_together(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->grade('K')->get();

        $this->assertSame(100.0, $result->enrollmentTotal);
        $this->assertCount(1, $result->grades);
        $this->assertSame('K', $result->grades->sole()->grade);
    }

    public function test_total_sums_every_selected_dataset_flat(): void
    {
        $total = $this->makeQuery($this->fakeRepository())->district(self::AUN)->withEnglishLearners()->total();

        $this->assertSame(230.0, $total); // 100 + 110 general, + 20 EL
    }

    public function test_sole_throws_when_more_than_one_year_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('Expected exactly one');

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->sole();
    }

    public function test_sole_returns_the_single_year_for_an_explicit_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2023-2024')->sole();

        $this->assertInstanceOf(EnrollmentYearSummary::class, $result);
        $this->assertSame('2023-2024', $result->schoolYear);
    }

    public function test_first_returns_the_earliest_year_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->first();

        $this->assertSame('2023-2024', $result->schoolYear);
    }

    public function test_unmatched_district_throws(): void
    {
        $repository = $this->fakeRepository(withDistrict: '999999999');

        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('No PDE data file matched');

        $this->makeQuery($repository)->district(self::AUN)->get();
    }

    public function test_get_iterator_yields_every_matched_year(): void
    {
        $years = iterator_to_array($this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears());

        $this->assertCount(2, $years);
        $this->assertContainsOnlyInstancesOf(EnrollmentYearSummary::class, $years);
    }

    private function makeQuery(EnrollmentDataRepository $repository): EnrollmentQuery
    {
        return new EnrollmentQuery($repository, $this->app);
    }

    /**
     * A repository double covering two actual years (2023-2024, 2024-2025),
     * one EL year (2024-2025 only), one economically disadvantaged year
     * (2024-2025), and one projection-only year (2025-2026, published for a
     * year that has no actual data yet - the scenario that makes "most
     * recent" ambiguous between actual and projected).
     */
    private function fakeRepository(string $withDistrict = self::AUN): EnrollmentDataRepository&\PHPUnit\Framework\MockObject\MockObject
    {
        $repository = $this->createMock(EnrollmentDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester', 'lea_type' => 'SD']];

        $repository->method('availablePublicYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2023-2024'),
        ]);
        $repository->method('availableElYears')->willReturn([FiscalYear::parse('2024-2025')]);
        $repository->method('availableProjectionYears')->willReturn([FiscalYear::parse('2025-2026')]);
        $repository->method('availableEconomicallyDisadvantagedYears')->willReturn([FiscalYear::parse('2024-2025')]);

        $repository->method('publicTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new YearTable($district, [$withDistrict => ['K5F' => 100.0, '001' => 110.0]], []),
                '2023-2024' => new YearTable($district, [$withDistrict => ['K5F' => 90.0, '001' => 95.0]], []),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $repository->method('projectionTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2025-2026' => new YearTable($district, [$withDistrict => ['K5F' => 130.0, '001' => 110.0]], []),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $repository->method('elTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new YearTable($district, [$withDistrict => ['K' => 20.0]], []),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        $repository->method('economicallyDisadvantagedTable')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new RowTable($district, [
                    $withDistrict => [['economically_disadvantaged' => 500.0, 'enrollment' => 1000.0, 'percent' => 50.0]],
                ]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
