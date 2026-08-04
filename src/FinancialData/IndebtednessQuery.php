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
 * Fluent query over one district's Statement of Indebtedness - short- and
 * long-term debt outstanding, broken down by fund type and phase (see
 * IndebtednessRecord).
 *
 *     PDE::district()->indebtedness()->get();                                   // every year, every fund type/phase
 *     PDE::district()->year('2024-2025')->indebtedness()->fundType('governmental')->phase('end')->sole();
 *
 * Omitting year() returns every year published (2015-16 onward). Each
 * (district, year) contributes up to 10 records: 2 "all fund types" summary
 * lines (beginning/end) plus 4 phases each for governmental and proprietary
 * fund types.
 *
 * @implements IteratorAggregate<int, IndebtednessRecord>
 */
class IndebtednessQuery implements AcceptsQueryContext, IteratorAggregate
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    /** @var list<string>|null null = all fund types */
    private ?array $fundTypes = null;

    /** @var list<string>|null null = all phases */
    private ?array $phases = null;

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

    /** Restrict to fund type(s): 'governmental', 'proprietary', 'all'. */
    public function fundType(string ...$fundTypes): static
    {
        $this->fundTypes = array_values(array_map(fn (string $t) => strtolower(trim($t)), $fundTypes));

        return $this;
    }

    /** Restrict to phase(s): 'beginning', 'additional', 'retirements', 'end'. */
    public function phase(string ...$phases): static
    {
        $this->phases = array_values(array_map(fn (string $p) => strtolower(trim($p)), $phases));

        return $this;
    }

    /**
     * @return Collection<int, IndebtednessRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->year !== null ? [$this->year] : $this->repository->availableIndebtednessYears();

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $table = $this->tryTable(fn () => $this->repository->indebtedness($year));

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
                if ($this->fundTypes !== null && ! in_array($row['fund_type'], $this->fundTypes, true)) {
                    continue;
                }

                if ($this->phases !== null && ! in_array($row['phase'], $this->phases, true)) {
                    continue;
                }

                $records->push(new IndebtednessRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    fiscalYear: $year->long(),
                    fundType: $row['fund_type'],
                    phase: $row['phase'],
                    total: $row['total'],
                    categories: $row['categories'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested indebtedness data");
        }

        return $records
            ->sortBy([['fiscalYear', 'asc'], ['fundType', 'asc'], ['phase', 'asc']])
            ->values();
    }

    public function first(): ?IndebtednessRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - narrow with fundType()/phase()
     * first, since a district/year combination has up to 10 records.
     */
    public function sole(): IndebtednessRecord
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
            $this->fundTypes !== null ? 'fund type(s) ['.implode(', ', $this->fundTypes).']' : null,
            $this->phases !== null ? 'phase(s) ['.implode(', ', $this->phases).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
