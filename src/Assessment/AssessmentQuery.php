<?php

namespace WiserWebSolutions\PDEClient\Assessment;

use ArrayIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's PSSA/Keystone proficiency results.
 *
 *     PDE::district()->assessments()->get();                       // both exams, every year
 *     PDE::district()->year('2024-2025')->assessments()->pssa()->get();
 *     PDE::district()->assessments()->keystone()->subject('Algebra I')->allStudents()->get();
 *     PDE::district()->assessments()->pssa()->subject('Math')->grade('Total')->allStudents()->get();
 *
 * Years follow the package's school-year convention ('2024-2025' = the
 * spring-2025 testing window PDE labels "2025"). Omitting year() returns
 * every year available for the selected exam(s). No 2019-2020 data exists
 * (COVID cancelled that administration).
 *
 * @implements IteratorAggregate<int, AssessmentRecord>
 */
class AssessmentQuery implements AcceptsQueryContext, IteratorAggregate
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    /** @var list<string>|null null = both exams */
    private ?array $exams = null;

    /** @var list<string>|null case-insensitive subject filter */
    private ?array $subjects = null;

    /** @var list<string>|null tested-grade filter ('3'..'8', '11', 'Total') */
    private ?array $grades = null;

    /** @var list<string>|null case-insensitive student-group filter */
    private ?array $groups = null;

    public function __construct(private readonly AssessmentDataRepository $repository)
    {
    }

    /**
     * Selects the LEA by its 9-digit AUN. Called with no argument (or never
     * called), the configured default district applies.
     */
    public function district(?string $aun = null): static
    {
        $aun ??= config('pde-client.default_district');

        if ($aun === null || trim((string) $aun) === '') {
            throw new PDEClientException(
                'No district given and no default configured - set pde-client.default_district (PDE_CLIENT_DEFAULT_AUN) or pass an AUN.'
            );
        }

        $this->aun = trim((string) $aun);

        return $this;
    }

    public function year(string|int|FiscalYear $year): static
    {
        $this->year = FiscalYear::parse($year);

        return $this;
    }

    /** PSSA results only (grades 3-8). */
    public function pssa(): static
    {
        $this->exams = [AssessmentRecord::EXAM_PSSA];

        return $this;
    }

    /** Keystone exam results only (grade 11). */
    public function keystone(): static
    {
        $this->exams = [AssessmentRecord::EXAM_KEYSTONE];

        return $this;
    }

    /** e.g. subject('Math'), subject('Algebra I', 'Biology'). Case-insensitive. */
    public function subject(string ...$subjects): static
    {
        $this->subjects = array_values(array_map(fn (string $s) => Str::lower(trim($s)), $subjects));

        return $this;
    }

    /** Tested grade(s): '3'-'8' (PSSA), '11' (Keystone), or 'Total'. */
    public function grade(string ...$grades): static
    {
        $this->grades = array_values(array_map('trim', $grades));

        return $this;
    }

    /** e.g. group('All Students'), group('Male', 'Female'). Case-insensitive. */
    public function group(string ...$groups): static
    {
        $this->groups = array_values(array_map(fn (string $g) => Str::lower(trim($g)), $groups));

        return $this;
    }

    /** Shortcut for group('All Students'). */
    public function allStudents(): static
    {
        return $this->group('All Students');
    }

    /**
     * @return Collection<int, AssessmentRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $exams = $this->exams ?? [AssessmentRecord::EXAM_PSSA, AssessmentRecord::EXAM_KEYSTONE];

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($exams as $exam) {
            foreach ($this->resolveYears($exam) as $year) {
                $table = $this->tryTable($exam, $year);

                if ($table === null) {
                    continue;
                }

                $anyTableChecked = true;

                if (! isset($table->districts[$aun])) {
                    continue;
                }

                $districtSeen = true;
                $district = $table->districts[$aun];

                foreach ($table->rows[$aun] ?? [] as $row) {
                    if (! $this->passesFilters($row)) {
                        continue;
                    }

                    $records->push(new AssessmentRecord(
                        aun: $aun,
                        districtName: $district['name'] ?? null,
                        county: $district['county'] ?? null,
                        schoolYear: $year->long(),
                        exam: $exam,
                        subject: $row['subject'],
                        grade: $row['grade'],
                        group: $row['group'],
                        scored: $row['scored'],
                        percentAdvanced: $row['advanced'],
                        percentProficient: $row['proficient'],
                        percentBasic: $row['basic'],
                        percentBelowBasic: $row['below_basic'],
                        percentProficientOrAbove: $row['proficient_or_above'],
                    ));
                }
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested assessment data");
        }

        return $records
            ->sortBy([['schoolYear', 'asc'], ['exam', 'asc'], ['subject', 'asc'], ['grade', 'asc'], ['group', 'asc']])
            ->values();
    }

    public function first(): ?AssessmentRecord
    {
        return $this->get()->first();
    }

    public function sole(): AssessmentRecord
    {
        $records = $this->get();

        return match (true) {
            $records->isEmpty() => throw DataSetNotFoundException::noneMatched($this->filterDescription()),
            $records->count() > 1 => throw DataSetNotFoundException::multipleMatched($this->filterDescription(), $records->count()),
            default => $records->first(),
        };
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->get()->all());
    }

    private function resolveAun(): string
    {
        if ($this->aun === null) {
            $this->district();
        }

        return $this->aun;
    }

    /**
     * @return list<FiscalYear>
     */
    private function resolveYears(string $exam): array
    {
        if ($this->year !== null) {
            return [$this->year];
        }

        return $this->repository->availableYears($exam);
    }

    /**
     * A requested year may exist for one exam but not the other (or not at
     * all when year() was set explicitly) - for a query spanning exams and
     * years, that's just nothing to add rather than an error.
     */
    private function tryTable(string $exam, FiscalYear $year): ?RowTable
    {
        try {
            return $this->repository->table($exam, $year);
        } catch (PDEClientException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function passesFilters(array $row): bool
    {
        if ($this->subjects !== null && ! in_array(Str::lower($row['subject']), $this->subjects, true)) {
            return false;
        }

        if ($this->grades !== null && ! in_array($row['grade'], $this->grades, true)) {
            return false;
        }

        if ($this->groups !== null && ! in_array(Str::lower($row['group']), $this->groups, true)) {
            return false;
        }

        return true;
    }

    private function filterDescription(): string
    {
        $parts = array_filter([
            "district [{$this->aun}]",
            $this->year !== null ? "year [{$this->year->short()}]" : null,
            $this->exams !== null ? implode('+', $this->exams) : null,
            $this->subjects !== null ? 'subject(s) ['.implode(', ', $this->subjects).']' : null,
            $this->grades !== null ? 'grade(s) ['.implode(', ', $this->grades).']' : null,
            $this->groups !== null ? 'group(s) ['.implode(', ', $this->groups).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
