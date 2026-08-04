<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;

/**
 * One cohort-graduation-rate line for one LEA: a school year, a cohort span
 * (4-, 5-, or 6-year), and a student group.
 *
 * `rate` is a fraction (0-1) exactly as PDE stores it, null where PDE left
 * the cell blank (group absent or too small). `graduates`/`cohortSize` are
 * only published for the 'Total' group; they're null for every demographic
 * group.
 */
#[MapName(SnakeCaseMapper::class)]
final class GraduationRecord extends Data
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $leaName,
        public readonly ?string $leaType,
        public readonly string $schoolYear,
        public readonly CohortSpan $cohortYears,
        public readonly string $group,
        public readonly ?float $graduates,
        public readonly ?float $cohortSize,
        public readonly ?float $rate,
    ) {
    }
}
