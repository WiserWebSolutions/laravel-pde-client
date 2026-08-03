<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One school year's dropout summary for one LEA. `rate` is a fraction (0-1)
 * as PDE stores it: dropouts during the year divided by the LEA's fall
 * enrollment.
 */
final class DropoutRecord implements Arrayable
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $leaName,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly ?float $enrollment,
        public readonly ?float $maleDropouts,
        public readonly ?float $femaleDropouts,
        public readonly ?float $dropouts,
        public readonly ?float $rate,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'lea_name' => $this->leaName,
            'county' => $this->county,
            'school_year' => $this->schoolYear,
            'enrollment' => $this->enrollment,
            'male_dropouts' => $this->maleDropouts,
            'female_dropouts' => $this->femaleDropouts,
            'dropouts' => $this->dropouts,
            'rate' => $this->rate,
        ];
    }
}
