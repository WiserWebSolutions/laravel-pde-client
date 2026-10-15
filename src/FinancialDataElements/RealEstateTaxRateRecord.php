<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;

/**
 * One millage line for one district in one school year. A district spanning
 * more than one county has one record per county; a handful of counties
 * further split the rate by assessment type - both are distinguished only by
 * `notes` (PDE's own free-text "Municipality / Other Info" column), which is
 * null for the common single-rate-per-county case.
 */
#[MapName(SnakeCaseMapper::class)]
final class RealEstateTaxRateRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly string $schoolYear;

    public readonly ?string $county;

    public readonly ?string $notes;

    public readonly ?float $mills;

    public readonly ?float $communityCollegeMills;

    public function __construct(
        string $aun,
        ?string $districtName,
        string $schoolYear,
        ?string $county,
        ?string $notes,
        ?float $mills,
        ?float $communityCollegeMills,
        ?AvailabilityMetadata $availability = null,
        ?DataProvenance $provenance = null,
    ) {
        $values = compact('aun', 'districtName', 'schoolYear', 'county', 'notes', 'mills', 'communityCollegeMills');
        $this->provenance = DataProvenance::forValues($values, $provenance ?? $availability?->provenance);
        $this->initializeData($values, $availability);
        $this->aun = $aun;
        if ($this->availability('districtName') === DataAvailability::NotCollected) {
            $this->markUnavailable('districtName');
        } else {
            $this->districtName = $districtName;
        }
        $this->schoolYear = $schoolYear;
        if ($this->availability('county') === DataAvailability::NotCollected) {
            $this->markUnavailable('county');
        } else {
            $this->county = $county;
        }
        if ($this->availability('notes') === DataAvailability::NotCollected) {
            $this->markUnavailable('notes');
        } else {
            $this->notes = $notes;
        }
        if ($this->availability('mills') === DataAvailability::NotCollected) {
            $this->markUnavailable('mills');
        } else {
            $this->mills = $mills;
        }
        if ($this->availability('communityCollegeMills') === DataAvailability::NotCollected) {
            $this->markUnavailable('communityCollegeMills');
        } else {
            $this->communityCollegeMills = $communityCollegeMills;
        }
    }
}
