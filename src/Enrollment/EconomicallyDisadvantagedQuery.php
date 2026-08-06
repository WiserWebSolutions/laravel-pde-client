<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use ArrayIterator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's economically disadvantaged (low-income)
 * student counts. Part of the "enrollments" category, reached via
 * ->enrollments()->economicallyDisadvantaged().
 *
 *     PDE::district()->enrollments()->economicallyDisadvantaged()->get();                  // most recent year
 *     PDE::district()->year('2024-2025')->enrollments()->economicallyDisadvantaged()->sole();
 *
 * Omitting year() returns just the most recent year published (2016-17
 * onward) - call allYears()/years()/year('all') for every year instead.
 *
 * @implements IteratorAggregate<int, EconomicallyDisadvantagedRecord>
 */
class EconomicallyDisadvantagedQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    public function __construct(private readonly EnrollmentDataRepository $repository)
    {
    }

    /**
     * @return Collection<int, EconomicallyDisadvantagedRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears($this->repository->availableEconomicallyDisadvantagedYears());

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->economicallyDisadvantagedTable($year));

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
                $records->push(new EconomicallyDisadvantagedRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    leaType: $district['lea_type'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    economicallyDisadvantagedCount: $row['economically_disadvantaged'],
                    enrollment: $row['enrollment'],
                    percentEconomicallyDisadvantaged: $row['percent'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested economically disadvantaged data");
        }

        return $records->sortBy('schoolYear')->values();
    }

    public function first(): ?EconomicallyDisadvantagedRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - for "this district's
     * economically disadvantaged count this year", not "whichever year
     * happened to sort first".
     */
    public function sole(): EconomicallyDisadvantagedRecord
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
        ]);

        return implode(', ', $parts);
    }
}
