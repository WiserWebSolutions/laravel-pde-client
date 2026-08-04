<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use ArrayIterator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enums\PersonnelCategory;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's professional staff summary data
 * (full-time staff: headcounts by gender plus average salary, years of
 * service, LEA tenure, and education level, per staff category).
 *
 *     PDE::district()->personnel()->get();                          // every category, most recent year
 *     PDE::district()->year('2025-2026')->personnel()->classroomTeachers()->sole()->averageSalary;
 *     PDE::district()->personnel()->category('administrator')->get();
 *
 * Omitting year() returns just the most recent year published (2012-13
 * onward) - call allYears()/years()/year('all') for every year instead.
 *
 * @implements IteratorAggregate<int, PersonnelRecord>
 */
class PersonnelQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    /** @var list<PersonnelCategory>|null null = all categories */
    private ?array $categories = null;

    public function __construct(private readonly PersonnelDataRepository $repository)
    {
    }

    /**
     * Restrict to staff categories: PersonnelCategory::Professional (the PP
     * total), ::Administrator, ::ClassroomTeacher, ::Coordinator, ::Other
     * (or their string values).
     */
    public function category(PersonnelCategory|string ...$categories): static
    {
        $this->categories = array_values(array_map(
            fn (PersonnelCategory|string $c) => $c instanceof PersonnelCategory ? $c : PersonnelCategory::from(strtolower(trim($c))),
            $categories,
        ));

        return $this;
    }

    /** Shortcut for category(PersonnelCategory::ClassroomTeacher). */
    public function classroomTeachers(): static
    {
        return $this->category(PersonnelCategory::ClassroomTeacher);
    }

    /** Shortcut for category(PersonnelCategory::Administrator). */
    public function administrators(): static
    {
        return $this->category(PersonnelCategory::Administrator);
    }

    /**
     * @return Collection<int, PersonnelRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears($this->repository->availableYears());

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
                $category = PersonnelCategory::from($row['category']);

                if ($this->categories !== null && ! in_array($category, $this->categories, true)) {
                    continue;
                }

                $records->push(new PersonnelRecord(
                    aun: $aun,
                    leaName: $district['name'] ?? null,
                    leaType: $district['lea_type'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    category: $category,
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
            ->sortBy(fn (PersonnelRecord $record) => [$record->schoolYear, $record->category->value])
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
            $this->categories !== null ? 'category(ies) ['.implode(', ', array_map(fn (PersonnelCategory $c) => $c->value, $this->categories)).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
