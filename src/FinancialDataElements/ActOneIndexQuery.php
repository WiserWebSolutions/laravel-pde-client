<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use ArrayIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's Act 1 adjusted index - the maximum
 * property tax increase allowed without PDE exception or voter approval. Part
 * of the "financials" category, reached via ->financials()->actOneIndex().
 *
 *     PDE::district()->financials()->actOneIndex()->get();                        // most recent year
 *     PDE::district()->year('2024-2025')->financials()->actOneIndex()->sole()->index;
 *
 * Omitting year() returns just the most recent year published (2015-16
 * onward) - call allYears()/years()/year('all') for every year instead.
 *
 * @implements IteratorAggregate<int, ActOneIndexRecord>
 */
class ActOneIndexQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    public function __construct(private readonly ActOneIndexRepository $repository) {}

    /**
     * @return Collection<int, ActOneIndexRecord>
     */
    public function get(): Collection
    {
        return $this->records()->collect()->sortBy('schoolYear')->values();
    }

    public function records(): LazyCollection
    {
        $query = clone $this;

        return LazyCollection::make(fn () => $query->iterateRecords());
    }

    private function iterateRecords(): \Generator
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears(fn () => $this->repository->availableYears());

        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->table($year));

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
                yield new ActOneIndexRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    index: $row['index'],
                    availability: $table->availabilityFor($aun, $rowIndex, ['index' => 'index'], ['dataset' => 'ActOneIndexRecord', 'year' => $year->long()]),
                );
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested Act 1 Index data");
        }

    }

    public function first(): ?ActOneIndexRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - for "this district's Act 1
     * index this year", not "whichever year happened to sort first".
     */
    public function sole(): ActOneIndexRecord
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
        ]);

        return implode(', ', $parts);
    }
}
