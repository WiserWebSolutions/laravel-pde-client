<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\RealEstateTaxRateRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\SelectedDataRecord;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;

/**
 * One fiscal year's financial data for one LEA, merging every sibling
 * dataset FinancialQuery selected (fundBalance, indebtedness,
 * realEstateTaxRates, selectedData, actOneIndex - each opted into with its
 * matching withX()/withAllDatasets() call) into a single per-year record,
 * alongside every account-code FinancialRecord this query matched, nested in
 * `accounts` for drill-down. Produced by
 * FinancialQuery::get()/first()/sole() - the year-level view this query
 * returns in place of a flat Collection<FinancialRecord>.
 *
 * Each sibling field is always present as a property, but stays null unless
 * its matching withX() call was made (or the sibling dataset simply has no
 * published data for this year) - so null means "not queried/not
 * published". `indebtedness` and `realEstateTaxRates` can each have more
 * than one record for a single year (see IndebtednessRecord/
 * RealEstateTaxRateRecord), so they're collections rather than single
 * records; a Collection that's merely empty (as opposed to null) means the
 * dataset was queried but PDE published nothing for this year.
 *
 * @property Collection<int, IndebtednessRecord>|null $indebtedness
 * @property Collection<int, RealEstateTaxRateRecord>|null $realEstateTaxRates
 * @property Collection<int, FinancialRecord> $accounts
 */
#[MapName(SnakeCaseMapper::class)]
final class FinancialYearSummary extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $fiscalYear;

    public readonly ?FundBalanceRecord $fundBalance;

    public readonly ?Collection $indebtedness;

    public readonly ?Collection $realEstateTaxRates;

    public readonly ?SelectedDataRecord $selectedData;

    public readonly ?ActOneIndexRecord $actOneIndex;

    public readonly Collection $accounts;

    /**
     * @param  Collection<int, IndebtednessRecord>|null  $indebtedness
     * @param  Collection<int, RealEstateTaxRateRecord>|null  $realEstateTaxRates
     * @param  Collection<int, FinancialRecord>  $accounts
     */
    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $fiscalYear,
        ?FundBalanceRecord $fundBalance,
        ?Collection $indebtedness,
        ?Collection $realEstateTaxRates,
        ?SelectedDataRecord $selectedData,
        ?ActOneIndexRecord $actOneIndex,
        Collection $accounts,
        ?AvailabilityMetadata $availability = null,
        ?DataProvenance $provenance = null,
    ) {
        $values = compact('aun', 'districtName', 'county', 'fiscalYear', 'fundBalance', 'indebtedness', 'realEstateTaxRates', 'selectedData', 'actOneIndex', 'accounts');
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
        if ($this->availability('fundBalance') === DataAvailability::NotCollected) {
            $this->markUnavailable('fundBalance');
        } else {
            $this->fundBalance = $fundBalance;
        }
        if ($this->availability('indebtedness') === DataAvailability::NotCollected) {
            $this->markUnavailable('indebtedness');
        } else {
            $this->indebtedness = $indebtedness;
        }
        if ($this->availability('realEstateTaxRates') === DataAvailability::NotCollected) {
            $this->markUnavailable('realEstateTaxRates');
        } else {
            $this->realEstateTaxRates = $realEstateTaxRates;
        }
        if ($this->availability('selectedData') === DataAvailability::NotCollected) {
            $this->markUnavailable('selectedData');
        } else {
            $this->selectedData = $selectedData;
        }
        if ($this->availability('actOneIndex') === DataAvailability::NotCollected) {
            $this->markUnavailable('actOneIndex');
        } else {
            $this->actOneIndex = $actOneIndex;
        }
        $this->accounts = $accounts;
    }
}
