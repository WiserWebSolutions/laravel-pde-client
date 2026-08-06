<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One district's Act 1 adjusted index for one school year - the maximum
 * property tax increase (as a fraction, e.g. 0.041 for 4.1%) that district
 * may levy without PDE exception or voter approval. Already adjusted for the
 * district's own MV/PI aid ratio where PDE applies that adjustment (base
 * index x (0.75 + aid ratio) for districts above a 0.4000 aid ratio) - the
 * statewide base index itself isn't modeled here, since it has no district
 * dimension to query by (see ActOneIndexParser).
 */
#[MapName(SnakeCaseMapper::class)]
final class ActOneIndexRecord extends Data
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly ?float $index,
    ) {
    }
}
