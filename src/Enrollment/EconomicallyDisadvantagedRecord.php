<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;

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
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $leaType;

    public readonly ?string $county;

    public readonly string $schoolYear;

    public readonly ?float $economicallyDisadvantagedCount;

    public readonly ?float $enrollment;

    public readonly ?float $percentEconomicallyDisadvantaged;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $leaType,
        ?string $county,
        string $schoolYear,
        ?float $economicallyDisadvantagedCount,
        ?float $enrollment,
        ?float $percentEconomicallyDisadvantaged,
        ?AvailabilityMetadata $availability = null,
    ) {
        $this->initializeData(compact('aun', 'districtName', 'leaType', 'county', 'schoolYear', 'economicallyDisadvantagedCount', 'enrollment', 'percentEconomicallyDisadvantaged'), $availability);
        $this->aun = $aun;
        if ($this->availability('districtName') === DataAvailability::NotCollected) {
            $this->markUnavailable('districtName');
        } else {
            $this->districtName = $districtName;
        }
        if ($this->availability('leaType') === DataAvailability::NotCollected) {
            $this->markUnavailable('leaType');
        } else {
            $this->leaType = $leaType;
        }
        if ($this->availability('county') === DataAvailability::NotCollected) {
            $this->markUnavailable('county');
        } else {
            $this->county = $county;
        }
        $this->schoolYear = $schoolYear;
        if ($this->availability('economicallyDisadvantagedCount') === DataAvailability::NotCollected) {
            $this->markUnavailable('economicallyDisadvantagedCount');
        } else {
            $this->economicallyDisadvantagedCount = $economicallyDisadvantagedCount;
        }
        if ($this->availability('enrollment') === DataAvailability::NotCollected) {
            $this->markUnavailable('enrollment');
        } else {
            $this->enrollment = $enrollment;
        }
        if ($this->availability('percentEconomicallyDisadvantaged') === DataAvailability::NotCollected) {
            $this->markUnavailable('percentEconomicallyDisadvantaged');
        } else {
            $this->percentEconomicallyDisadvantaged = $percentEconomicallyDisadvantaged;
        }
    }
}
