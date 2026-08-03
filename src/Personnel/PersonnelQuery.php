<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use ArrayIterator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's professional staff summary data
 * (full-time staff: headcounts by gender plus average salary, years of
 * service, LEA tenure, and education level, per staff category).
 *
 *     PDE::district()->personnel()->get();                          // every category, every year
 *     PDE::district()->year('2025-2026')->personnel()->classroomTeachers()->sole()->averageSalary;
 *     PDE::district()->personnel()->category('administrator')->get();
 *
 * Omitting year() returns every year published (2012-13 onward).
 *
 * @implements IteratorAggregate<int, PersonnelRecord>
 */
class PersonnelQuery implements AcceptsQueryContext, IteratorAggregate
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    /** @var list<string>|null null = all categories */
    private ?array $categories = null;

    public function __construct(private readonly PersonnelDataRepository $repository)
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
     * Restrict to staff categories: 'professional' (the PP total),
     * 'administrator', 'classroom_teacher', 'coordinator', 'other'.
     */
    public function category(string ...$categories): static
    {
        $this->categories = array_values(array_map(fn (string $c) => strtolower(trim($c)), $categories));

        return $this;
    }

    /** Shortcut for category('classroom_teacher'). */
    public function classroomTeachers(): static
    {
        return $this->category(PersonnelRecord::CATEGORY_CLASSROOM_TEACHER);
    }

    /** Shortcut for category('administrator'). */
    public function administrators(): static
    {
        return $this->category(PersonnelRecord::CATEGORY_ADMINISTRATOR);
    }

    /**
     * @return Collection<int, PersonnelRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->year !== null ? [$this->year] : $this->repository->availableYears();

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable($year);

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
                if ($this->categories !== null && ! in_array($row['category'], $this->categories, true)) {
                    continue;
                }

                $records->push(new PersonnelRecord(
                    aun: $aun,
                    leaName: $district['name'] ?? null,
                    leaType: $district['lea_type'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    category: $row['category'],
                    count: $row['count'],
                    femaleCount: $row['female'],
                    maleCount: $row['male'],
                    averageSalary: $row['salary'],
                    averageYearsService: $row['service'],
                    averageYearsInLea: $row['lea_years'],
                    averageEducationLevel: $row['education'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested personnel data");
        }

        return $records
            ->sortBy([['schoolYear', 'asc'], ['category', 'asc']])
            ->values();
    }

    public function first(): ?PersonnelRecord
    {
        return $this->get()->first();
    }

    public function sole(): PersonnelRecord
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

    private function tryTable(FiscalYear $year): ?RowTable
    {
        try {
            return $this->repository->table($year);
        } catch (PDEClientException) {
            return null;
        }
    }

    private function filterDescription(): string
    {
        $parts = array_filter([
            "district [{$this->aun}]",
            $this->year !== null ? "year [{$this->year->short()}]" : null,
            $this->categories !== null ? 'category(ies) ['.implode(', ', $this->categories).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
