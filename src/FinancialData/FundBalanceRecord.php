<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;

/**
 * One district's year-end general fund balance breakdown (account codes
 * 0830/0840/0850, from the AFR) for one fiscal year - not to be confused
 * with FinancialQuery::fundBalances(), which covers the GFB's *beginning*-
 * of-year budgeted 08xx codes from an entirely different workbook.
 */
#[MapName(SnakeCaseMapper::class)]
final class FundBalanceRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $fiscalYear;

    public readonly ?float $committed;

    public readonly ?float $assigned;

    public readonly ?float $unassigned;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $fiscalYear,
        ?float $committed,
        ?float $assigned,
        ?float $unassigned,
        ?AvailabilityMetadata $availability = null,
    ) {
        $this->initializeData(compact('aun', 'districtName', 'county', 'fiscalYear', 'committed', 'assigned', 'unassigned'), $availability);
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
        if ($this->availability('committed') === DataAvailability::NotCollected) {
            $this->markUnavailable('committed');
        } else {
            $this->committed = $committed;
        }
        if ($this->availability('assigned') === DataAvailability::NotCollected) {
            $this->markUnavailable('assigned');
        } else {
            $this->assigned = $assigned;
        }
        if ($this->availability('unassigned') === DataAvailability::NotCollected) {
            $this->markUnavailable('unassigned');
        } else {
            $this->unassigned = $unassigned;
        }
    }

    /** Sum of whichever of committed/assigned/unassigned are present; null if none are. */
    public function total(): ?float
    {
        $values = [$this->committed, $this->assigned, $this->unassigned];

        return in_array(null, $values, true) ? null : round(array_sum($values), 2);
    }
}
