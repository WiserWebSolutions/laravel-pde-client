<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One (fund type, phase) line of a district's Statement of Indebtedness for
 * one fiscal year. `fundType` is 'governmental', 'proprietary', or 'all'
 * (PDE's own top-level summary, not broken down by fund type); `phase` is
 * 'beginning', 'additional', 'retirements', or 'end' ('all' only ever has
 * 'beginning'/'end'). `categories` breaks `total` down by PDE's own debt
 * category labels for that year, verbatim - see IndebtednessParser for why
 * these aren't normalized across years.
 */
final class IndebtednessRecord implements Arrayable
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $fiscalYear,
        public readonly string $fundType,
        public readonly string $phase,
        public readonly ?float $total,
        public readonly array $categories,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'county' => $this->county,
            'fiscal_year' => $this->fiscalYear,
            'fund_type' => $this->fundType,
            'phase' => $this->phase,
            'total' => $this->total,
            'categories' => $this->categories,
        ];
    }
}
