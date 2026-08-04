<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

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
 * Fluent query over one district's real estate (millage) tax rates.
 *
 *     PDE::district()->realEstateTaxRates()->get();                    // every year, every county line
 *     PDE::district()->year('2024-2025')->realEstateTaxRates()->get(); // one year, every county line
 *
 * A district spanning more than one county returns multiple records for that
 * year - one per county (see RealEstateTaxRateRecord). Omitting year()
 * returns every year published (2016-17 onward).
 *
 * @implements IteratorAggregate<int, RealEstateTaxRateRecord>
 */
class RealEstateTaxRateQuery implements AcceptsQueryContext, IteratorAggregate
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    public function __construct(private readonly FinancialDataElementsRepository $repository)
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
     * @return Collection<int, RealEstateTaxRateRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->year !== null ? [$this->year] : $this->repository->availableTaxRateYears();

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->taxRateTable($year));

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
                $records->push(new RealEstateTaxRateRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    schoolYear: $year->long(),
                    county: $row['county'],
                    notes: $row['notes'],
                    mills: $row['mills'],
                    communityCollegeMills: $row['community_college_mills'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested real estate tax rate data");
        }

        return $records->sortBy(['schoolYear', 'county'])->values();
    }

    public function first(): ?RealEstateTaxRateRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - use when the district is known
     * to have exactly one county line for the requested year(s).
     */
    public function sole(): RealEstateTaxRateRecord
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
