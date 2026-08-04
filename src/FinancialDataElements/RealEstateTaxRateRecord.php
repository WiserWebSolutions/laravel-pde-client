<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One millage line for one district in one school year. A district spanning
 * more than one county has one record per county; a handful of counties
 * further split the rate by assessment type - both are distinguished only by
 * `notes` (PDE's own free-text "Municipality / Other Info" column), which is
 * null for the common single-rate-per-county case.
 */
#[MapName(SnakeCaseMapper::class)]
final class RealEstateTaxRateRecord extends Data
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly string $schoolYear,
        public readonly ?string $county,
        public readonly ?string $notes,
        public readonly float $mills,
        public readonly ?float $communityCollegeMills,
    ) {
    }
}
