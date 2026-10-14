<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;

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
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $leaName;

    public readonly ?string $leaType;

    public readonly string $schoolYear;

    public readonly CohortSpan $cohortYears;

    public readonly string $group;

    public readonly ?float $graduates;

    public readonly ?float $cohortSize;

    public readonly ?float $rate;

    public function __construct(
        string $aun,
        ?string $leaName,
        ?string $leaType,
        string $schoolYear,
        CohortSpan $cohortYears,
        string $group,
        ?float $graduates,
        ?float $cohortSize,
        ?float $rate,
        ?AvailabilityMetadata $availability = null,
    ) {
        $this->initializeData(compact('aun', 'leaName', 'leaType', 'schoolYear', 'cohortYears', 'group', 'graduates', 'cohortSize', 'rate'), $availability);
        $this->aun = $aun;
        if ($this->availability('leaName') === DataAvailability::NotCollected) {
            $this->markUnavailable('leaName');
        } else {
            $this->leaName = $leaName;
        }
        if ($this->availability('leaType') === DataAvailability::NotCollected) {
            $this->markUnavailable('leaType');
        } else {
            $this->leaType = $leaType;
        }
        $this->schoolYear = $schoolYear;
        $this->cohortYears = $cohortYears;
        $this->group = $group;
        if ($this->availability('graduates') === DataAvailability::NotCollected) {
            $this->markUnavailable('graduates');
        } else {
            $this->graduates = $graduates;
        }
        if ($this->availability('cohortSize') === DataAvailability::NotCollected) {
            $this->markUnavailable('cohortSize');
        } else {
            $this->cohortSize = $cohortSize;
        }
        if ($this->availability('rate') === DataAvailability::NotCollected) {
            $this->markUnavailable('rate');
        } else {
            $this->rate = $rate;
        }
    }
}
