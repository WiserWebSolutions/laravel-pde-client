<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One cohort-graduation-rate line for one LEA: a school year, a cohort span
 * (4-, 5-, or 6-year), and a student group.
 *
 * `rate` is a fraction (0-1) exactly as PDE stores it, null where PDE left
 * the cell blank (group absent or too small). `graduates`/`cohortSize` are
 * only published for the 'Total' group; they're null for every demographic
 * group.
 */
final class GraduationRecord implements Arrayable
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $leaName,
        public readonly ?string $leaType,
        public readonly string $schoolYear,
        public readonly int $cohortYears,
        public readonly string $group,
        public readonly ?float $graduates,
        public readonly ?float $cohortSize,
        public readonly ?float $rate,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'lea_name' => $this->leaName,
            'lea_type' => $this->leaType,
            'school_year' => $this->schoolYear,
            'cohort_years' => $this->cohortYears,
            'group' => $this->group,
            'graduates' => $this->graduates,
            'cohort_size' => $this->cohortSize,
            'rate' => $this->rate,
        ];
    }
}
