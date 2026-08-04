<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

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
 * Fluent query over one district's "Selected Data" bundle - aid ratio, WADM/
 * ADM, equalized mills, population density, and PDE's two raw per-pupil
 * expenditure figures (instructionExpensePerWadm, totalExpenditurePerAdm).
 * Part of the "financials" category, reached via
 * ->financials()->selectedData().
 *
 *     PDE::district()->financials()->selectedData()->get();                   // most recent year
 *     PDE::district()->year('2022-2023')->financials()->selectedData()->sole()->instructionExpensePerWadm;
 *
 * Omitting year() returns just the most recent year published (2013-14
 * onward) - call allYears()/years()/year('all') for every year instead.
 *
 * @implements IteratorAggregate<int, SelectedDataRecord>
 */
class SelectedDataQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    public function __construct(private readonly FinancialDataElementsRepository $repository)
    {
    }

    /**
     * @return Collection<int, SelectedDataRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears($this->repository->availableSelectedDataYears());

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->selectedDataTable($year));

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
                $records->push(new SelectedDataRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    aidRatio: $row['aid_ratio'],
                    aidRatioRank: $row['aid_ratio_rank'],
                    wadm: $row['wadm'],
                    adm: $row['adm'],
                    admRank: $row['adm_rank'],
                    equalizedMills: $row['equalized_mills'],
                    equalizedMillsRank: $row['equalized_mills_rank'],
                    populationPerSquareMile: $row['population_per_square_mile'],
                    populationPerSquareMileRank: $row['population_per_square_mile_rank'],
                    instructionExpensePerWadm: $row['instruction_expense_per_wadm'],
                    instructionExpensePerWadmRank: $row['instruction_expense_per_wadm_rank'],
                    totalExpenditurePerAdm: $row['total_expenditure_per_adm'],
                    totalExpenditurePerAdmRank: $row['total_expenditure_per_adm_rank'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested Selected Data");
        }

        return $records->sortBy('schoolYear')->values();
    }

    public function first(): ?SelectedDataRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - for "this district's Selected
     * Data this year", not "whichever year happened to sort first".
     */
    public function sole(): SelectedDataRecord
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
