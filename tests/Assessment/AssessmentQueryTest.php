<?php

namespace WiserWebSolutions\PDEClient\Tests\Assessment;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Assessment\AssessmentDataRepository;
use WiserWebSolutions\PDEClient\Assessment\AssessmentQuery;
use WiserWebSolutions\PDEClient\Assessment\AssessmentRecord;
use WiserWebSolutions\PDEClient\Enums\Exam;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\GraduationDataRepository;
use WiserWebSolutions\PDEClient\Graduation\GraduationQuery;
use WiserWebSolutions\PDEClient\Graduation\GraduationRecord;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * AssessmentQuery's own merging/filtering logic, tested against a hand-built
 * AssessmentDataRepository double - the download/parse pipeline is exercised
 * separately by AssessmentWorkbookParserTest and AssessmentIntegrationTest.
 */
#[AllowMockObjectsWithoutExpectations]
class AssessmentQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_default_query_returns_the_most_recent_year_for_both_exams(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->get();

        $this->assertInstanceOf(Collection::class, $result);

        // Most recent year (2024-2025) for BOTH pssa and keystone, since
        // each exam's "most recent" is resolved independently.
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => $r->schoolYear === '2024-2025'));
        $this->assertEqualsCanonicalizing(
            ['pssa', 'keystone'],
            $result->pluck('exam')->map(fn (Exam $e) => $e->value)->unique()->values()->all()
        );
    }

    public function test_pssa_excludes_keystone_entirely(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->pssa()->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => $r->exam === Exam::Pssa));
    }

    public function test_keystone_excludes_pssa_entirely(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->keystone()->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => $r->exam === Exam::Keystone));
    }

    public function test_subject_filter_is_case_insensitive(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->pssa()->subject('math')->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => strtolower($r->subject) === 'math'));
    }

    public function test_grade_filter_narrows_to_the_requested_grade(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->pssa()->grade('3')->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => $r->grade === '3'));
    }

    public function test_group_filter_is_case_insensitive(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->group('all students')->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => strtolower($r->group) === 'all students'));
    }

    public function test_all_students_is_shortcut_for_group_all_students(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->allStudents()->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => $r->group === 'All Students'));
    }

    public function test_explicit_year_pins_both_exams_to_that_year(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->year('2023-2024')->get();

        $this->assertTrue($result->isNotEmpty());
        $this->assertTrue($result->every(fn (AssessmentRecord $r) => $r->schoolYear === '2023-2024'));
    }

    public function test_all_years_returns_every_year_for_both_exams(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->pssa()->allYears()->get();

        $this->assertEqualsCanonicalizing(['2023-2024', '2024-2025'], $result->pluck('schoolYear')->unique()->values()->all());
    }

    public function test_sole_throws_when_more_than_one_record_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('Expected exactly one');

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();
    }

    public function test_sole_returns_the_single_matching_record(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)
            ->keystone()->subject('Algebra I')->grade('11')->allStudents()->sole();

        $this->assertInstanceOf(AssessmentRecord::class, $result);
        $this->assertSame('Algebra I', $result->subject);
    }

    public function test_first_returns_the_first_sorted_record(): void
    {
        $result = $this->makeQuery($this->fakeRepository())->district(self::AUN)->pssa()->first();

        $this->assertInstanceOf(AssessmentRecord::class, $result);
    }

    public function test_unmatched_district_throws(): void
    {
        $repository = $this->fakeRepository(withDistrict: '999999999');

        $this->expectException(DataSetNotFoundException::class);
        $this->expectExceptionMessage('No PDE data file matched');

        $this->makeQuery($repository)->district(self::AUN)->get();
    }

    public function test_a_missing_year_for_one_exam_does_not_break_the_other(): void
    {
        // 2019-2020 has no PSSA administration at all (COVID) - the query
        // must still succeed and just return nothing for that combination.
        $repository = $this->fakeRepository();

        $result = $this->makeQuery($repository)->district(self::AUN)->keystone()->year('2019-2020')->get();

        $this->assertTrue($result->isEmpty());
    }

    public function test_get_iterator_yields_every_matched_record(): void
    {
        $records = iterator_to_array($this->makeQuery($this->fakeRepository())->district(self::AUN)->pssa()->get());

        $this->assertContainsOnlyInstancesOf(AssessmentRecord::class, $records);
    }

    public function test_graduation_seeds_the_sibling_query_with_district_and_year(): void
    {
        $graduationRepository = $this->createMock(GraduationDataRepository::class);

        $district = [self::AUN => ['name' => 'Phoenixville Area SD', 'lea_type' => 'SD']];

        $graduationRepository->method('availableCohortYears')->willReturn([FiscalYear::parse('2023-2024')]);
        $graduationRepository->method('cohortTable')->willReturnCallback(
            function (\WiserWebSolutions\PDEClient\Enums\CohortSpan $span, FiscalYear $year) use ($district) {
                return match ($year->long()) {
                    '2023-2024' => new RowTable($district, [
                        self::AUN => [['group' => 'Total', 'graduates' => 90.0, 'cohort_size' => 100.0, 'rate' => 0.9]],
                    ]),
                    default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
                };
            }
        );

        $this->app->instance(GraduationDataRepository::class, $graduationRepository);

        $assessmentQuery = $this->makeQuery($this->createMock(AssessmentDataRepository::class));

        $graduationQuery = $assessmentQuery->district(self::AUN)->year('2023-2024')->graduation();

        $this->assertInstanceOf(GraduationQuery::class, $graduationQuery);

        $result = $graduationQuery->sole();

        $this->assertInstanceOf(GraduationRecord::class, $result);
        $this->assertSame(self::AUN, $result->aun);
        $this->assertSame('2023-2024', $result->schoolYear);
    }

    private function makeQuery(AssessmentDataRepository $repository): AssessmentQuery
    {
        return new AssessmentQuery($repository, $this->app);
    }

    /**
     * A repository double covering two years (2023-2024, 2024-2025) for
     * both pssa and keystone, each with a couple of subject/grade/group
     * combinations, plus a 2019-2020 gap (no administration that year, as
     * really happened) for keystone.
     */
    private function fakeRepository(string $withDistrict = self::AUN): AssessmentDataRepository
    {
        $repository = $this->createMock(AssessmentDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']];

        $repository->method('availableYears')->willReturnCallback(fn (string $exam) => [
            FiscalYear::parse('2024-2025'),
            FiscalYear::parse('2023-2024'),
        ]);

        $pssaRow = fn (string $subject, string $grade, string $group, float $base) => [
            'subject' => $subject,
            'group' => $group,
            'grade' => $grade,
            'scored' => $base,
            'advanced' => $base * 0.1,
            'proficient' => $base * 0.4,
            'basic' => $base * 0.3,
            'below_basic' => $base * 0.2,
            'proficient_or_above' => $base * 0.5,
        ];

        $repository->method('table')->willReturnCallback(function (string $exam, FiscalYear $year) use ($district, $withDistrict, $pssaRow) {
            if ($exam === 'pssa') {
                return match ($year->long()) {
                    '2024-2025' => new RowTable($district, [
                        $withDistrict => [
                            $pssaRow('Math', '3', 'All Students', 100.0),
                            $pssaRow('Math', 'Total', 'All Students', 400.0),
                            $pssaRow('ELA', '3', 'Male', 50.0),
                        ],
                    ]),
                    '2023-2024' => new RowTable($district, [
                        $withDistrict => [
                            $pssaRow('Math', '3', 'All Students', 90.0),
                        ],
                    ]),
                    default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
                };
            }

            // keystone
            return match ($year->long()) {
                '2024-2025' => new RowTable($district, [
                    $withDistrict => [
                        $pssaRow('Algebra I', '11', 'All Students', 200.0),
                    ],
                ]),
                '2023-2024' => new RowTable($district, [
                    $withDistrict => [
                        $pssaRow('Algebra I', '11', 'All Students', 180.0),
                    ],
                ]),
                default => throw DataSetNotFoundException::noneMatched("year [{$year->long()}]"),
            };
        });

        return $repository;
    }
}
