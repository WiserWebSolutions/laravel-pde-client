<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One school year's dropout summary for one LEA. `rate` is a fraction (0-1)
 * as PDE stores it: dropouts during the year divided by the LEA's fall
 * enrollment.
 */
#[MapName(SnakeCaseMapper::class)]
final class DropoutRecord extends Data
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
}
