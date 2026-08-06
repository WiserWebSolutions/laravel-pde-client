<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One district's economically disadvantaged (low-income) student count for
 * one school year, from PDE's "Ten Year Low Income and Enrollment History"
 * workbook. `enrollment` is the same-year total enrollment PDE used as the
 * denominator for `percentEconomicallyDisadvantaged` - it may differ
 * slightly from the public enrollment dataset's own total, since the two
 * are sourced from different PDE reports.
 */
#[MapName(SnakeCaseMapper::class)]
final class EconomicallyDisadvantagedRecord extends Data
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $leaType,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly ?float $economicallyDisadvantagedCount,
        public readonly ?float $enrollment,
        public readonly ?float $percentEconomicallyDisadvantaged,
    ) {
    }
}
