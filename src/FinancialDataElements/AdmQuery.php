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
 * Fluent query over one district's Average Daily Membership figures. Part of
 * the "enrollments" category, reached via
 * ->enrollments()->averageDailyMembership() (or ->enrollments()->adm()).
 *
 *     PDE::district()->enrollments()->averageDailyMembership()->get();              // most recent year
 *     PDE::district()->year('2024-2025')->enrollments()->adm()->sole();
 *
 * Omitting year() returns just the most recent year published (2015-16
 * onward) - call allYears()/years()/year('all') for every year instead.
 *
 * @implements IteratorAggregate<int, AdmRecord>
 */
class AdmQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    public function __construct(private readonly FinancialDataElementsRepository $repository)
    {
    }

    /**
     * @return Collection<int, AdmRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears($this->repository->availableAdmYears());

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->admTable($year));

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
                $records->push(new AdmRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    schoolYear: $year->long(),
                    adm: $row['adm'],
                    wadm: $row['wadm'],
                    adjustedAdm: $row['adjusted_adm'],
                    nonresidentAdm: $row['nonresident_adm'],
                    totalAdmPde363: $row['total_adm_pde363'],
                    specialEducationAdm: $row['special_education_adm'],
                    adjustmentFactor: $row['adjustment_factor'],
                    breakdown: $row['breakdown'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested ADM data");
        }

        return $records->sortBy('schoolYear')->values();
    }

    public function first(): ?AdmRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - for "this district's ADM this
     * year", not "whichever year happened to sort first".
     */
    public function sole(): AdmRecord
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
