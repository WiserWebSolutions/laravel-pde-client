<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;

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
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $schoolYear;

    public readonly ?float $index;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $schoolYear,
        ?float $index,
        ?AvailabilityMetadata $availability = null,
    ) {
        $this->initializeData(compact('aun', 'districtName', 'county', 'schoolYear', 'index'), $availability);
        $this->aun = $aun;
        if ($this->availability('districtName') === DataAvailability::NotCollected) {
            $this->markUnavailable('districtName');
        } else {
            $this->districtName = $districtName;
        }
        if ($this->availability('county') === DataAvailability::NotCollected) {
            $this->markUnavailable('county');
        } else {
            $this->county = $county;
        }
        $this->schoolYear = $schoolYear;
        if ($this->availability('index') === DataAvailability::NotCollected) {
            $this->markUnavailable('index');
        } else {
            $this->index = $index;
        }
    }
}
