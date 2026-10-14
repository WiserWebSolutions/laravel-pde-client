<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use ArrayIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's graduation outcomes: cohort graduation
 * rates by default (the standard 4-year rate unless cohortYears() says
 * otherwise), or dropout summaries via dropouts(). Part of the "assessments"
 * category, reached via ->assessments()->graduation().
 *
 *     PDE::district()->assessments()->graduation()->get();                 // 4-year rates, every group, most recent year
 *     PDE::district()->year('2023-2024')->assessments()->graduation()->group('Total')->sole();
 *     PDE::district()->assessments()->graduation()->cohortYears(6)->get(); // 6-year rates
 *     PDE::district()->assessments()->graduation()->dropouts()->get();     // Collection<DropoutRecord>
 *
 * get() returns Collection<GraduationRecord>, or Collection<DropoutRecord>
 * after dropouts() - the two populations measure different things (finishing
 * within N years vs. leaving during a single year) and don't merge. Omitting
 * year() returns just the most recent year available - call
 * allYears()/years()/year('all') for every year available instead.
 *
 * @implements IteratorAggregate<int, GraduationRecord|DropoutRecord>
 */
class GraduationQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    private CohortSpan $cohortYears = CohortSpan::FourYear;

    private bool $dropouts = false;

    /** @var list<string>|null case-insensitive student-group filter (cohort mode only) */
    private ?array $groups = null;

    public function __construct(private readonly GraduationDataRepository $repository) {}

    /**
     * Which cohort span the rates cover: students graduating within 4
     * (default - the standard headline rate), 5, or 6 years of entering
     * 9th grade.
     */
    public function cohortYears(CohortSpan|int $span): static
    {
        $span = $span instanceof CohortSpan ? $span : CohortSpan::tryFrom($span);

        if ($span === null) {
            throw new PDEClientException('Cohort span must be 4, 5, or 6.');
        }

        $this->cohortYears = $span;

        return $this;
    }

    /** Dropout summaries instead of cohort graduation rates. */
    public function dropouts(): static
    {
        $this->dropouts = true;

        return $this;
    }

    /** e.g. group('Total'), group('Male', 'Female'). Case-insensitive; cohort mode only. */
    public function group(string ...$groups): static
    {
        $this->groups = array_values(array_map(fn (string $g) => Str::lower(trim($g)), $groups));

        return $this;
    }

    /**
     * @return Collection<int, GraduationRecord>|Collection<int, DropoutRecord>
     */
    public function get(): Collection
    {
        return $this->records()->collect()->sortBy(fn ($record) => [$record->schoolYear, $record instanceof GraduationRecord ? $record->group : ''])->values();
    }

    public function records(): LazyCollection
    {
        $query = clone $this;

        return LazyCollection::make(fn () => $query->dropouts ? $query->getDropouts() : $query->getCohortRates());
    }

    public function first(): GraduationRecord|DropoutRecord|null
    {
        return $this->get()->first();
    }

    public function sole(): GraduationRecord|DropoutRecord
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

    /**
     * @return \Generator<int, GraduationRecord>
     */
    private function getCohortRates(): \Generator
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears(fn () => $this->repository->availableCohortYears($this->cohortYears));

        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->cohortTable($this->cohortYears, $year));

            if ($table === null) {
                continue;
            }

            $anyTableChecked = true;

            if (! isset($table->districts[$aun])) {
                continue;
            }

            if ($this->groups !== null) {
                $table->assertGroupsCollected($this->groups, ['dataset' => 'graduation', 'district' => $aun, 'year' => $year->long()]);
            }
            $districtSeen = true;
            $district = $table->districts[$aun];

            foreach ($table->rows[$aun] ?? [] as $rowIndex => $row) {
                if ($this->groups !== null && ! in_array(Str::lower($row['group']), $this->groups, true)) {
                    continue;
                }

                yield new GraduationRecord(
                    aun: $aun,
                    leaName: $district['name'] ?? null,
                    leaType: $district['lea_type'] ?? null,
                    schoolYear: $year->long(),
                    cohortYears: $this->cohortYears,
                    group: $row['group'],
                    graduates: $row['graduates'],
                    cohortSize: $row['cohort_size'],
                    rate: $row['rate'],
                    availability: $table->availabilityFor($aun, $rowIndex, ['group' => 'group', 'graduates' => 'graduates', 'cohort_size' => 'cohortSize', 'rate' => 'rate'], ['dataset' => 'GraduationRecord', 'year' => $year->long()]),
                );
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested graduation data");
        }

    }

    /**
     * @return \Generator<int, DropoutRecord>
     */
    private function getDropouts(): \Generator
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears(fn () => $this->repository->availableDropoutYears());

        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->dropoutTable($year));

            if ($table === null) {
                continue;
            }

            $anyTableChecked = true;

            if (! isset($table->districts[$aun])) {
                continue;
            }

            $districtSeen = true;
            $district = $table->districts[$aun];

            foreach ($table->rows[$aun] ?? [] as $rowIndex => $row) {
                yield new DropoutRecord(
                    aun: $aun,
                    leaName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    enrollment: $row['enrollment'],
                    maleDropouts: $row['male_dropouts'],
                    femaleDropouts: $row['female_dropouts'],
                    dropouts: $row['dropouts'],
                    rate: $row['rate'],
                    availability: $table->availabilityFor($aun, $rowIndex, ['enrollment' => 'enrollment', 'male_dropouts' => 'maleDropouts', 'female_dropouts' => 'femaleDropouts', 'dropouts' => 'dropouts', 'rate' => 'rate'], ['dataset' => 'DropoutRecord', 'year' => $year->long()]),
                );
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested dropout data");
        }

    }

    /**
     * @param  callable(): RowTable  $load
     */
    private function tryTable(callable $load): ?RowTable
    {
        try {
            return $load();
        } catch (DataSetNotFoundException $exception) {
            if ($exception->matches > 1) {
                throw $exception;
            }

            return null;
        }
    }

    private function filterDescription(): string
    {
        $parts = array_filter([
            "district [{$this->aun}]",
            $this->year !== null ? "year [{$this->year->short()}]" : null,
            $this->dropouts ? 'dropouts' : "{$this->cohortYears->value}-year cohort",
            $this->groups !== null ? 'group(s) ['.implode(', ', $this->groups).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
