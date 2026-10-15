<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;

/**
 * One school year's dropout summary for one LEA. `rate` is a fraction (0-1)
 * as PDE stores it: dropouts during the year divided by the LEA's fall
 * enrollment.
 */
#[MapName(SnakeCaseMapper::class)]
final class DropoutRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $leaName;

    public readonly ?string $county;

    public readonly string $schoolYear;

    public readonly ?float $enrollment;

    public readonly ?float $maleDropouts;

    public readonly ?float $femaleDropouts;

    public readonly ?float $dropouts;

    public readonly ?float $rate;

    public function __construct(
        string $aun,
        ?string $leaName,
        ?string $county,
        string $schoolYear,
        ?float $enrollment,
        ?float $maleDropouts,
        ?float $femaleDropouts,
        ?float $dropouts,
        ?float $rate,
        ?AvailabilityMetadata $availability = null,
        ?DataProvenance $provenance = null,
    ) {
        $values = compact('aun', 'leaName', 'county', 'schoolYear', 'enrollment', 'maleDropouts', 'femaleDropouts', 'dropouts', 'rate');
        $this->provenance = DataProvenance::forValues($values, $provenance ?? $availability?->provenance);
        $this->initializeData($values, $availability);
        $this->aun = $aun;
        if ($this->availability('leaName') === DataAvailability::NotCollected) {
            $this->markUnavailable('leaName');
        } else {
            $this->leaName = $leaName;
        }
        if ($this->availability('county') === DataAvailability::NotCollected) {
            $this->markUnavailable('county');
        } else {
            $this->county = $county;
        }
        $this->schoolYear = $schoolYear;
        if ($this->availability('enrollment') === DataAvailability::NotCollected) {
            $this->markUnavailable('enrollment');
        } else {
            $this->enrollment = $enrollment;
        }
        if ($this->availability('maleDropouts') === DataAvailability::NotCollected) {
            $this->markUnavailable('maleDropouts');
        } else {
            $this->maleDropouts = $maleDropouts;
        }
        if ($this->availability('femaleDropouts') === DataAvailability::NotCollected) {
            $this->markUnavailable('femaleDropouts');
        } else {
            $this->femaleDropouts = $femaleDropouts;
        }
        if ($this->availability('dropouts') === DataAvailability::NotCollected) {
            $this->markUnavailable('dropouts');
        } else {
            $this->dropouts = $dropouts;
        }
        if ($this->availability('rate') === DataAvailability::NotCollected) {
            $this->markUnavailable('rate');
        } else {
            $this->rate = $rate;
        }
    }
}
