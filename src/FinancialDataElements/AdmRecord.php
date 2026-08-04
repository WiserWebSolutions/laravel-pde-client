<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

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
#[MapName(SnakeCaseMapper::class)]
final class AdmRecord extends Data
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
}
