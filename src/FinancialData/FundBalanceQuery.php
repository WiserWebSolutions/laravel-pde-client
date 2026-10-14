<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

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
 * Fluent query over one district's year-end general fund balance
 * (committed/assigned/unassigned, account codes 0830/0840/0850, from the
 * AFR) - a distinct dataset from FinancialQuery::fundBalances() (the GFB's
 * *beginning*-of-year budgeted 08xx codes). Part of the "financials"
 * category, reached via ->financials()->fundBalance().
 *
 *     PDE::district()->financials()->fundBalance()->get();                       // most recent year
 *     PDE::district()->year('2024-2025')->financials()->fundBalance()->sole();
 *
 * Omitting year() returns just the most recent year published (2015-16
 * onward) - call allYears()/years()/year('all') for every year instead.
 *
 * @implements IteratorAggregate<int, FundBalanceRecord>
 */
class FundBalanceQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    public function __construct(private readonly FinancialDataRepository $repository) {}

    /**
     * @return Collection<int, FundBalanceRecord>
     */
    public function get(): Collection
    {
        return $this->records()->collect()->sortBy('fiscalYear')->values();
    }

    public function records(): LazyCollection
    {
        $query = clone $this;

        return LazyCollection::make(fn () => $query->iterateRecords());
    }

    private function iterateRecords(): \Generator
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears(fn () => $this->repository->availableFundBalanceYears());

        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->fundBalance($year));

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
                yield new FundBalanceRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    fiscalYear: $year->long(),
                    committed: $row['committed'],
                    assigned: $row['assigned'],
                    unassigned: $row['unassigned'],
                    availability: $table->availabilityFor($aun, $rowIndex, ['committed' => 'committed', 'assigned' => 'assigned', 'unassigned' => 'unassigned'], ['dataset' => 'FundBalanceRecord', 'year' => $year->long()]),
                );
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested fund balance data");
        }

    }

    public function first(): ?FundBalanceRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - for "this district's fund
     * balance this year", not "whichever year happened to sort first".
     */
    public function sole(): FundBalanceRecord
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
