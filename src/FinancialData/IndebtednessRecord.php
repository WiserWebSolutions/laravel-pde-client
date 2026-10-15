<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\DebtPhase;
use WiserWebSolutions\PDEClient\Enums\FundType;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\MetricMap;

/**
 * One (fund type, phase) line of a district's Statement of Indebtedness for
 * one fiscal year. `phase` is 'beginning', 'additional', 'retirements', or
 * 'end' (`FundType::All` only ever has 'beginning'/'end'). `categories`
 * breaks `total` down by PDE's own debt category labels for that year,
 * verbatim - see IndebtednessParser for why these aren't normalized across
 * years.
 */
#[MapName(SnakeCaseMapper::class)]
final class IndebtednessRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $fiscalYear;

    public readonly FundType $fundType;

    public readonly DebtPhase $phase;

    public readonly ?float $total;

    public readonly ?MetricMap $categories;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $fiscalYear,
        FundType $fundType,
        DebtPhase $phase,
        ?float $total,
        array|MetricMap|null $categories,
        ?AvailabilityMetadata $availability = null,
        ?DataProvenance $provenance = null,
    ) {
        $values = compact('aun', 'districtName', 'county', 'fiscalYear', 'fundType', 'phase', 'total', 'categories');
        $this->provenance = DataProvenance::forValues($values, $provenance ?? $availability?->provenance);
        $this->initializeData($values, $availability);
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
        $this->fiscalYear = $fiscalYear;
        $this->fundType = $fundType;
        $this->phase = $phase;
        if ($this->availability('total') === DataAvailability::NotCollected) {
            $this->markUnavailable('total');
        } else {
            $this->total = $total;
        }
        if ($this->availability('categories') === DataAvailability::NotCollected) {
            $this->markUnavailable('categories');
        } else {
            $this->categories = $this->metricMap('categories', $categories);
        }
    }
}
