<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\PersonnelCategory;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;

/**
 * One staff category's full-time headcount and averages for one LEA in one
 * school year, from PDE's professional staff summary report.
 *
 * `category` Professional is PDE's "PP" TOTAL of the other four
 * (administrator, classroom_teacher, coordinator, other) - summing all five
 * double-counts, same caveat as financial rollup codes.
 *
 * The averages are as PDE publishes them: salary in dollars, service and
 * LEA tenure in years, and education on PDE's numeric attainment scale.
 * Averages missing from older workbooks come through as null.
 */
#[MapName(SnakeCaseMapper::class)]
final class PersonnelRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $leaName;

    public readonly ?string $leaType;

    public readonly ?string $county;

    public readonly string $schoolYear;

    public readonly PersonnelCategory $category;

    public readonly ?float $count;

    public readonly ?float $femaleCount;

    public readonly ?float $maleCount;

    public readonly ?float $averageSalary;

    public readonly ?float $averageYearsService;

    public readonly ?float $averageYearsInLea;

    public readonly ?float $averageEducationLevel;

    public function __construct(
        string $aun,
        ?string $leaName,
        ?string $leaType,
        ?string $county,
        string $schoolYear,
        PersonnelCategory $category,
        ?float $count,
        ?float $femaleCount,
        ?float $maleCount,
        ?float $averageSalary,
        ?float $averageYearsService,
        ?float $averageYearsInLea,
        ?float $averageEducationLevel,
        ?AvailabilityMetadata $availability = null,
    ) {
        $this->initializeData(compact('aun', 'leaName', 'leaType', 'county', 'schoolYear', 'category', 'count', 'femaleCount', 'maleCount', 'averageSalary', 'averageYearsService', 'averageYearsInLea', 'averageEducationLevel'), $availability);
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
        if ($this->availability('county') === DataAvailability::NotCollected) {
            $this->markUnavailable('county');
        } else {
            $this->county = $county;
        }
        $this->schoolYear = $schoolYear;
        $this->category = $category;
        if ($this->availability('count') === DataAvailability::NotCollected) {
            $this->markUnavailable('count');
        } else {
            $this->count = $count;
        }
        if ($this->availability('femaleCount') === DataAvailability::NotCollected) {
            $this->markUnavailable('femaleCount');
        } else {
            $this->femaleCount = $femaleCount;
        }
        if ($this->availability('maleCount') === DataAvailability::NotCollected) {
            $this->markUnavailable('maleCount');
        } else {
            $this->maleCount = $maleCount;
        }
        if ($this->availability('averageSalary') === DataAvailability::NotCollected) {
            $this->markUnavailable('averageSalary');
        } else {
            $this->averageSalary = $averageSalary;
        }
        if ($this->availability('averageYearsService') === DataAvailability::NotCollected) {
            $this->markUnavailable('averageYearsService');
        } else {
            $this->averageYearsService = $averageYearsService;
        }
        if ($this->availability('averageYearsInLea') === DataAvailability::NotCollected) {
            $this->markUnavailable('averageYearsInLea');
        } else {
            $this->averageYearsInLea = $averageYearsInLea;
        }
        if ($this->availability('averageEducationLevel') === DataAvailability::NotCollected) {
            $this->markUnavailable('averageEducationLevel');
        } else {
            $this->averageEducationLevel = $averageEducationLevel;
        }
    }
}
