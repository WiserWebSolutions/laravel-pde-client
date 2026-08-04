<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One district's year-end general fund balance breakdown (account codes
 * 0830/0840/0850, from the AFR) for one fiscal year - not to be confused
 * with FinancialQuery::fundBalances(), which covers the GFB's *beginning*-
 * of-year budgeted 08xx codes from an entirely different workbook.
 */
final class FundBalanceRecord implements Arrayable
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $fiscalYear,
        public readonly ?float $committed,
        public readonly ?float $assigned,
        public readonly ?float $unassigned,
    ) {
    }

    /** Sum of whichever of committed/assigned/unassigned are present; null if none are. */
    public function total(): ?float
    {
        $values = array_filter([$this->committed, $this->assigned, $this->unassigned], fn ($v) => $v !== null);

        return $values === [] ? null : round(array_sum($values), 2);
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'county' => $this->county,
            'fiscal_year' => $this->fiscalYear,
            'committed' => $this->committed,
            'assigned' => $this->assigned,
            'unassigned' => $this->unassigned,
        ];
    }
}
