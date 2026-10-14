<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\MetricMap;

/**
 * One district's Average Daily Membership figures for one school year.
 *
 * `nonresidentAdm`, `totalAdmPde363`, and `specialEducationAdm` are null
 * when their columns are absent from a recognized report (see AdmParser). `breakdown` carries the per-category
 * ADM/WADM detail exactly as PDE publishes it (e.g. "ADM Kindergarten HT5",
 * "WADM Elementary") - these categories are ADM-specific and don't line up
 * with Enrollment's PK/K/1-12 grade scale, so they're kept raw rather than
 * normalized.
 */
#[MapName(SnakeCaseMapper::class)]
final class AdmRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $schoolYear;

    public readonly ?float $adm;

    public readonly ?float $wadm;

    public readonly ?float $adjustedAdm;

    public readonly ?float $nonresidentAdm;

    public readonly ?float $totalAdmPde363;

    public readonly ?float $specialEducationAdm;

    public readonly ?float $adjustmentFactor;

    public readonly ?MetricMap $breakdown;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $schoolYear,
        ?float $adm,
        ?float $wadm,
        ?float $adjustedAdm,
        ?float $nonresidentAdm,
        ?float $totalAdmPde363,
        ?float $specialEducationAdm,
        ?float $adjustmentFactor,
        array|MetricMap|null $breakdown,
        ?AvailabilityMetadata $availability = null,
    ) {
        $this->initializeData(compact('aun', 'districtName', 'county', 'schoolYear', 'adm', 'wadm', 'adjustedAdm', 'nonresidentAdm', 'totalAdmPde363', 'specialEducationAdm', 'adjustmentFactor', 'breakdown'), $availability);
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
        if ($this->availability('adm') === DataAvailability::NotCollected) {
            $this->markUnavailable('adm');
        } else {
            $this->adm = $adm;
        }
        if ($this->availability('wadm') === DataAvailability::NotCollected) {
            $this->markUnavailable('wadm');
        } else {
            $this->wadm = $wadm;
        }
        if ($this->availability('adjustedAdm') === DataAvailability::NotCollected) {
            $this->markUnavailable('adjustedAdm');
        } else {
            $this->adjustedAdm = $adjustedAdm;
        }
        if ($this->availability('nonresidentAdm') === DataAvailability::NotCollected) {
            $this->markUnavailable('nonresidentAdm');
        } else {
            $this->nonresidentAdm = $nonresidentAdm;
        }
        if ($this->availability('totalAdmPde363') === DataAvailability::NotCollected) {
            $this->markUnavailable('totalAdmPde363');
        } else {
            $this->totalAdmPde363 = $totalAdmPde363;
        }
        if ($this->availability('specialEducationAdm') === DataAvailability::NotCollected) {
            $this->markUnavailable('specialEducationAdm');
        } else {
            $this->specialEducationAdm = $specialEducationAdm;
        }
        if ($this->availability('adjustmentFactor') === DataAvailability::NotCollected) {
            $this->markUnavailable('adjustmentFactor');
        } else {
            $this->adjustmentFactor = $adjustmentFactor;
        }
        if ($this->availability('breakdown') === DataAvailability::NotCollected) {
            $this->markUnavailable('breakdown');
        } else {
            $this->breakdown = $this->metricMap('breakdown', $breakdown);
        }
    }
}
