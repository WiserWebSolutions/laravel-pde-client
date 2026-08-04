<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One district's low-income (economically disadvantaged) student count for
 * one school year, from PDE's "Ten Year Low Income and Enrollment History"
 * workbook. `enrollment` is the same-year total enrollment PDE used as the
 * denominator for `percentLowIncome` - it may differ slightly from the
 * public enrollment dataset's own total, since the two are sourced from
 * different PDE reports.
 */
final class LowIncomeRecord implements Arrayable
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $leaType,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly ?float $lowIncomeCount,
        public readonly ?float $enrollment,
        public readonly ?float $percentLowIncome,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'lea_type' => $this->leaType,
            'county' => $this->county,
            'school_year' => $this->schoolYear,
            'low_income_count' => $this->lowIncomeCount,
            'enrollment' => $this->enrollment,
            'percent_low_income' => $this->percentLowIncome,
        ];
    }
}
