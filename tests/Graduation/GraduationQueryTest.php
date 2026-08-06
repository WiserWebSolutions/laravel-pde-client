<?php

namespace WiserWebSolutions\PDEClient\Tests\Graduation;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\DropoutRecord;
use WiserWebSolutions\PDEClient\Graduation\GraduationDataRepository;
use WiserWebSolutions\PDEClient\Graduation\GraduationQuery;
use WiserWebSolutions\PDEClient\Graduation\GraduationRecord;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * GraduationQuery's own merging/filtering logic (cohort rates by default,
 * dropouts() switches to the sibling dataset) against a hand-built
 * GraduationDataRepository double - the download/parse pipeline is exercised
 * by CohortRatesParserTest/DropoutsParserTest instead.
 */
#[AllowMockObjectsWithoutExpectations]
class GraduationQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_four_year_cohort_rates_for_the_most_recent_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->get();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertContainsOnlyInstancesOf(GraduationRecord::class, $result->all());
        $this->assertTrue($result->every(fn (GraduationRecord $r) => $r->schoolYear === '2024-2025'));
        $this->assertTrue($result->every(fn (GraduationRecord $r) => $r->cohortYears === CohortSpan::FourYear));
    }

    public function test_cohort_years_selects_the_requested_span(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('availableDropoutYears');

        $result = $this->makeQuery($repository)->district(self::AUN)->cohortYears(6)->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (GraduationRecord $r) => $r->cohortYears === CohortSpan::SixYear));
    }

    public function test_cohort_years_accepts_a_cohort_span_enum(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->cohortYears(CohortSpan::FiveYear)->get();

        $this->assertTrue($result->every(fn (GraduationRecord $r) => $r->cohortYears === CohortSpan::FiveYear));
    }

    public function test_cohort_years_rejects_an_invalid_span(): void
    {
        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('Cohort span must be 4, 5, or 6');

        $this->makeQuery($this->fakeRepository())->cohortYears(7);
    }

    public function test_group_filter_narrows_to_the_requested_group(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->group('total')->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (GraduationRecord $r) => strtolower($r->group) === 'total'));
    }

    public function test_total_group_carries_graduates_and_cohort_size(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->group('Total')->sole();

        $this->assertSame(90.0, $result->graduates);
        $this->assertSame(100.0, $result->cohortSize);
        $this->assertSame(0.9, $result->rate);
    }

    public function test_demographic_groups_have_null_graduates_and_cohort_size(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->group('Male')->sole();

        $this->assertNull($result->graduates);
        $this->assertNull($result->cohortSize);
        $this->assertSame(0.88, $result->rate);
    }

    public function test_dropouts_returns_dropout_records_instead_of_cohort_rates(): void
    {
        $repository = $this->fakeRepository();
        $repository->expects($this->never())->method('availableCohortYears');
        $repository->expects($this->never())->method('cohortTable');

        $result = $this->makeQuery($repository)->district(self::AUN)->dropouts()->get();

        $this->assertContainsOnlyInstancesOf(DropoutRecord::class, $result->all());
        $this->assertTrue($result->every(fn (DropoutRecord $r) => $r->schoolYear === '2024-2025'));
    }

    public function test_dropout_record_fields(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->dropouts()->sole();

        $this->assertSame(1000.0, $result->enrollment);
        $this->assertSame(5.0, $result->maleDropouts);
        $this->assertSame(3.0, $result->femaleDropouts);
        $this->assertSame(8.0, $result->dropouts);
        $this->assertSame(0.008, $result->rate);
    }

    public function test_group_filter_is_ignored_in_dropout_mode(): void
    {
        // group() only means anything for cohort rates - dropouts don't have
        // a per-group breakdown, so it must not silently empty the result.
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->group('Male')->dropouts()->get();

        $this->assertTrue($result->isNotEmpty());
    }

    public function test_all_years_returns_every_published_year_sorted_ascending(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->get();

        $this->assertSame(['2023-2024', '2024-2025'], $result->pluck('schoolYear')->unique()->values()->all());
    }

    public function test_sole_throws_when_more_than_one_record_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('Expected exactly one');

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();
    }

    public function test_first_returns_the_earliest_of_a_multi_year_result(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allYears()->group('Total')->first();

        $this->assertSame('2023-2024', $result->schoolYear);
    }

    public function test_unmatched_district_throws(): void
    {
        $repository = $this->fakeRepository(withDistrict: '999999999');

        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('No PDE data file matched');

        $this->makeQuery($repository)->district(self::AUN)->get();
    }

    public function test_get_iterator_yields_every_matched_record(): void
    {
        $records = iterator_to_array($this->makeQuery($this->fakeRepository())->district(self::AUN)->get());

        $this->assertContainsOnlyInstancesOf(GraduationRecord::class, $records);
    }

    private function makeQuery(GraduationDataRepository $repository): GraduationQuery
    {
        return new GraduationQuery($repository);
    }

    /**
     * A repository double covering two years (2023-2024, 2024-2025) of
     * 4-year AND 6-year cohort rates plus dropout summaries, each with a
     * Total and Male group where relevant.
     */
    private function fakeRepository(string $withDistrict = self::AUN): GraduationDataRepository
    {
        $repository = $this->createMock(GraduationDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'lea_type' => 'SD']];
        $dropoutDistrict = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableCohortYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2023-2024'),
        ]);
        $repository->method('availableDropoutYears')->willReturn([
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2023-2024'),
        ]);

        $repository->method('cohortTable')->willReturnCallback(function (CohortSpan $span, FiscalYear $year) use ($district, $withDistrict) {
            $rows = match ([$span, $year->long()]) {
                default => null,
            };

            return match (true) {
                $span === CohortSpan::FourYear && $year->long() === '2024-2025' => new RowTable($district, [
                    $withDistrict => [
                        ['group' => 'Total', 'graduates' => 90.0, 'cohort_size' => 100.0, 'rate' => 0.9],
                        ['group' => 'Male', 'graduates' => null, 'cohort_size' => null, 'rate' => 0.88],
                    ],
                ]),
                $span === CohortSpan::FourYear && $year->long() === '2023-2024' => new RowTable($district, [
                    $withDistrict => [
                        ['group' => 'Total', 'graduates' => 85.0, 'cohort_size' => 100.0, 'rate' => 0.85],
                        ['group' => 'Male', 'graduates' => null, 'cohort_size' => null, 'rate' => 0.83],
                    ],
                ]),
                $span === CohortSpan::SixYear && $year->long() === '2024-2025' => new RowTable($district, [
                    $withDistrict => [
                        ['group' => 'Total', 'graduates' => 95.0, 'cohort_size' => 100.0, 'rate' => 0.95],
                    ],
                ]),
                $span === CohortSpan::FiveYear && $year->long() === '2024-2025' => new RowTable($district, [
                    $withDistrict => [
                        ['group' => 'Total', 'graduates' => 93.0, 'cohort_size' => 100.0, 'rate' => 0.93],
                    ],
                ]),
                default => throw \WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException::noneMatched(
                    "a {$span->value}-year cohort graduation workbook for [{$year->long()}]"
                ),
            };
        });

        $repository->method('dropoutTable')->willReturnCallback(function (FiscalYear $year) use ($dropoutDistrict, $withDistrict) {
            return match ($year->long()) {
                '2024-2025' => new RowTable($dropoutDistrict, [
                    $withDistrict => [[
                        'enrollment' => 1000.0, 'male_dropouts' => 5.0, 'female_dropouts' => 3.0,
                        'dropouts' => 8.0, 'rate' => 0.008,
                    ]],
                ]),
                '2023-2024' => new RowTable($dropoutDistrict, [
                    $withDistrict => [[
                        'enrollment' => 950.0, 'male_dropouts' => 4.0, 'female_dropouts' => 2.0,
                        'dropouts' => 6.0, 'rate' => 0.0063,
                    ]],
                ]),
                default => throw \WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException::noneMatched(
                    "a dropout summary workbook for [{$year->long()}]"
                ),
            };
        });

        return $repository;
    }
}
