<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

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
 * Fluent query over one district's year-end general fund balance
 * (committed/assigned/unassigned, account codes 0830/0840/0850, from the
 * AFR) - a distinct dataset from FinancialQuery::fundBalances() (the GFB's
 * *beginning*-of-year budgeted 08xx codes).
 *
 *     PDE::district()->fundBalance()->get();                       // every year published
 *     PDE::district()->year('2024-2025')->fundBalance()->sole();
 *
 * Omitting year() returns every year published (2015-16 onward).
 *
 * @implements IteratorAggregate<int, FundBalanceRecord>
 */
class FundBalanceQuery implements AcceptsQueryContext, IteratorAggregate
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    public function __construct(private readonly FinancialDataRepository $repository)
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
     * @return Collection<int, FundBalanceRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->year !== null ? [$this->year] : $this->repository->availableFundBalanceYears();

        $records = collect();
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

            foreach ($table->rows[$aun] ?? [] as $row) {
                $records->push(new FundBalanceRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    fiscalYear: $year->long(),
                    committed: $row['committed'],
                    assigned: $row['assigned'],
                    unassigned: $row['unassigned'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested fund balance data");
        }

        return $records->sortBy('fiscalYear')->values();
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
