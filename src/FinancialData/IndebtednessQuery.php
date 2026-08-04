<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use ArrayIterator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enums\DebtPhase;
use WiserWebSolutions\PDEClient\Enums\FundType;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Fluent query over one district's Statement of Indebtedness - short- and
 * long-term debt outstanding, broken down by fund type and phase (see
 * IndebtednessRecord). Part of the "financials" category, reached via
 * ->financials()->indebtedness().
 *
 *     PDE::district()->financials()->indebtedness()->get();                                   // most recent year, every fund type/phase
 *     PDE::district()->year('2024-2025')->financials()->indebtedness()->fundType('governmental')->phase('end')->sole();
 *
 * Omitting year() returns just the most recent year published (2015-16
 * onward) - call allYears()/years()/year('all') for every year instead. Each
 * (district, year) contributes up to 10 records: 2 "all fund types" summary
 * lines (beginning/end) plus 4 phases each for governmental and proprietary
 * fund types.
 *
 * @implements IteratorAggregate<int, IndebtednessRecord>
 */
class IndebtednessQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    /** @var list<FundType>|null null = all fund types */
    private ?array $fundTypes = null;

    /** @var list<DebtPhase>|null null = all phases */
    private ?array $phases = null;

    public function __construct(private readonly FinancialDataRepository $repository)
    {
    }

    /** Restrict to fund type(s): FundType::Governmental, FundType::Proprietary, FundType::All (or their string values). */
    public function fundType(FundType|string ...$fundTypes): static
    {
        $this->fundTypes = array_values(array_map(
            fn (FundType|string $t) => $t instanceof FundType ? $t : FundType::from(strtolower(trim($t))),
            $fundTypes,
        ));

        return $this;
    }

    /** Restrict to phase(s): DebtPhase::Beginning, ::Additional, ::Retirements, ::End (or their string values). */
    public function phase(DebtPhase|string ...$phases): static
    {
        $this->phases = array_values(array_map(
            fn (DebtPhase|string $p) => $p instanceof DebtPhase ? $p : DebtPhase::from(strtolower(trim($p))),
            $phases,
        ));

        return $this;
    }

    /**
     * @return Collection<int, IndebtednessRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->selectYears($this->repository->availableIndebtednessYears());

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
                $fundType = FundType::from($row['fund_type']);
                $phase = DebtPhase::from($row['phase']);

                if ($this->fundTypes !== null && ! in_array($fundType, $this->fundTypes, true)) {
                    continue;
                }

                if ($this->phases !== null && ! in_array($phase, $this->phases, true)) {
                    continue;
                }

                $records->push(new IndebtednessRecord(
                    aun: $aun,
                    districtName: $district['name'] ?? null,
                    county: $district['county'] ?? null,
                    fiscalYear: $year->long(),
                    fundType: $fundType,
                    phase: $phase,
                    total: $row['total'],
                    categories: $row['categories'],
                ));
            }
        }

        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested indebtedness data");
        }

        return $records
            ->sortBy(fn (IndebtednessRecord $record) => [$record->fiscalYear, $record->fundType->value, $record->phase->value])
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
            $this->fundTypes !== null ? 'fund type(s) ['.implode(', ', array_map(fn (FundType $t) => $t->value, $this->fundTypes)).']' : null,
            $this->phases !== null ? 'phase(s) ['.implode(', ', array_map(fn (DebtPhase $p) => $p->value, $this->phases)).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
