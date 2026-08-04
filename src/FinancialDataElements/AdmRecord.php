<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One district's Average Daily Membership figures for one school year.
 *
 * `nonresidentAdm`, `totalAdmPde363`, and `specialEducationAdm` are null
 * before 2024-25 (see AdmParser). `breakdown` carries the per-category
 * ADM/WADM detail exactly as PDE publishes it (e.g. "ADM Kindergarten HT5",
 * "WADM Elementary") - these categories are ADM-specific and don't line up
 * with Enrollment's PK/K/1-12 grade scale, so they're kept raw rather than
 * normalized.
 */
final class AdmRecord implements Arrayable
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly ?float $adm,
        public readonly ?float $wadm,
        public readonly ?float $adjustedAdm,
        public readonly ?float $nonresidentAdm,
        public readonly ?float $totalAdmPde363,
        public readonly ?float $specialEducationAdm,
        public readonly ?float $adjustmentFactor,
        public readonly array $breakdown,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'county' => $this->county,
            'school_year' => $this->schoolYear,
            'adm' => $this->adm,
            'wadm' => $this->wadm,
            'adjusted_adm' => $this->adjustedAdm,
            'nonresident_adm' => $this->nonresidentAdm,
            'total_adm_pde363' => $this->totalAdmPde363,
            'special_education_adm' => $this->specialEducationAdm,
            'adjustment_factor' => $this->adjustmentFactor,
            'breakdown' => $this->breakdown,
        ];
    }
}
