<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use ArrayIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's graduation outcomes: cohort graduation
 * rates by default (the standard 4-year rate unless cohortYears() says
 * otherwise), or dropout summaries via dropouts().
 *
 *     PDE::district()->graduation()->get();                       // 4-year rates, every group, every year
 *     PDE::district()->year('2023-2024')->graduation()->group('Total')->sole();
 *     PDE::district()->graduation()->cohortYears(6)->get();       // 6-year rates
 *     PDE::district()->graduation()->dropouts()->get();           // Collection<DropoutRecord>
 *
 * get() returns Collection<GraduationRecord>, or Collection<DropoutRecord>
 * after dropouts() - the two populations measure different things (finishing
 * within N years vs. leaving during a single year) and don't merge.
 *
 * @implements IteratorAggregate<int, GraduationRecord|DropoutRecord>
 */
class GraduationQuery implements AcceptsQueryContext, IteratorAggregate
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    private CohortSpan $cohortYears = CohortSpan::FourYear;

    private bool $dropouts = false;

    /** @var list<string>|null case-insensitive student-group filter (cohort mode only) */
    private ?array $groups = null;

    public function __construct(private readonly GraduationDataRepository $repository)
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
        return $this->dropouts ? $this->getDropouts() : $this->getCohortRates();
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
     * @return Collection<int, GraduationRecord>
     */
    private function getCohortRates(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->year !== null ? [$this->year] : $this->repository->availableCohortYears($this->cohortYears);

        $records = collect();
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

            $districtSeen = true;
            $district = $table->districts[$aun];

            foreach ($table->rows[$aun] ?? [] as $row) {
                if ($this->groups !== null && ! in_array(Str::lower($row['group']), $this->groups, true)) {
                    continue;
                }

                $records->push(new GraduationRecord(
                    aun: $aun,
                    leaName: $district['name'] ?? null,
                    leaType: $district['lea_type'] ?? null,
                    schoolYear: $year->long(),
                    cohortYears: $this->cohortYears,
                    group: $row['group'],
                    graduates: $row['graduates'],
                    cohortSize: $row['cohort_size'],
                    rate: $row['rate'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested graduation data");
        }

        return $records
            ->sortBy([['schoolYear', 'asc'], ['group', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, DropoutRecord>
     */
    private function getDropouts(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->year !== null ? [$this->year] : $this->repository->availableDropoutYears();

        $records = collect();
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

            foreach ($table->rows[$aun] ?? [] as $row) {
                $records->push(new DropoutRecord(
                    aun: $aun,
                    leaName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    enrollment: $row['enrollment'],
                    maleDropouts: $row['male_dropouts'],
                    femaleDropouts: $row['female_dropouts'],
                    dropouts: $row['dropouts'],
                    rate: $row['rate'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested dropout data");
        }

        return $records->sortBy('schoolYear')->values();
    }

    private function resolveAun(): string
    {
        if ($this->aun === null) {
            $this->district();
        }

        return $this->aun;
    }

    /**
     * @param  callable(): RowTable  $load
     */
    private function tryTable(callable $load): ?RowTable
    {
        try {
            return $load();
        } catch (PDEClientException) {
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
