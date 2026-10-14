<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;

/**
 * One district's "Selected Data" bundle for one school year - PDE's own
 * headline per-district metrics, including its two raw per-pupil
 * expenditure figures: `instructionExpensePerWadm` (Actual Instruction
 * Expense per Weighted ADM) and `totalExpenditurePerAdm` (Total
 * Expenditures per ADM). Each metric except `wadm` is paired with its own
 * `*Rank` field (statewide rank, 1 = highest), null wherever PDE didn't
 * publish one. `aidRatio` is frequently labeled for a different (often
 * later) school year than the rest of the row, per PDE's own convention.
 */
#[MapName(SnakeCaseMapper::class)]
final class SelectedDataRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $schoolYear;

    public readonly ?float $aidRatio;

    public readonly ?float $aidRatioRank;

    public readonly ?float $wadm;

    public readonly ?float $adm;

    public readonly ?float $admRank;

    public readonly ?float $equalizedMills;

    public readonly ?float $equalizedMillsRank;

    public readonly ?float $populationPerSquareMile;

    public readonly ?float $populationPerSquareMileRank;

    public readonly ?float $instructionExpensePerWadm;

    public readonly ?float $instructionExpensePerWadmRank;

    public readonly ?float $totalExpenditurePerAdm;

    public readonly ?float $totalExpenditurePerAdmRank;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $schoolYear,
        ?float $aidRatio,
        ?float $aidRatioRank,
        ?float $wadm,
        ?float $adm,
        ?float $admRank,
        ?float $equalizedMills,
        ?float $equalizedMillsRank,
        ?float $populationPerSquareMile,
        ?float $populationPerSquareMileRank,
        ?float $instructionExpensePerWadm,
        ?float $instructionExpensePerWadmRank,
        ?float $totalExpenditurePerAdm,
        ?float $totalExpenditurePerAdmRank,
        ?AvailabilityMetadata $availability = null,
    ) {
        $this->initializeData(compact('aun', 'districtName', 'county', 'schoolYear', 'aidRatio', 'aidRatioRank', 'wadm', 'adm', 'admRank', 'equalizedMills', 'equalizedMillsRank', 'populationPerSquareMile', 'populationPerSquareMileRank', 'instructionExpensePerWadm', 'instructionExpensePerWadmRank', 'totalExpenditurePerAdm', 'totalExpenditurePerAdmRank'), $availability);
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
        if ($this->availability('aidRatio') === DataAvailability::NotCollected) {
            $this->markUnavailable('aidRatio');
        } else {
            $this->aidRatio = $aidRatio;
        }
        if ($this->availability('aidRatioRank') === DataAvailability::NotCollected) {
            $this->markUnavailable('aidRatioRank');
        } else {
            $this->aidRatioRank = $aidRatioRank;
        }
        if ($this->availability('wadm') === DataAvailability::NotCollected) {
            $this->markUnavailable('wadm');
        } else {
            $this->wadm = $wadm;
        }
        if ($this->availability('adm') === DataAvailability::NotCollected) {
            $this->markUnavailable('adm');
        } else {
            $this->adm = $adm;
        }
        if ($this->availability('admRank') === DataAvailability::NotCollected) {
            $this->markUnavailable('admRank');
        } else {
            $this->admRank = $admRank;
        }
        if ($this->availability('equalizedMills') === DataAvailability::NotCollected) {
            $this->markUnavailable('equalizedMills');
        } else {
            $this->equalizedMills = $equalizedMills;
        }
        if ($this->availability('equalizedMillsRank') === DataAvailability::NotCollected) {
            $this->markUnavailable('equalizedMillsRank');
        } else {
            $this->equalizedMillsRank = $equalizedMillsRank;
        }
        if ($this->availability('populationPerSquareMile') === DataAvailability::NotCollected) {
            $this->markUnavailable('populationPerSquareMile');
        } else {
            $this->populationPerSquareMile = $populationPerSquareMile;
        }
        if ($this->availability('populationPerSquareMileRank') === DataAvailability::NotCollected) {
            $this->markUnavailable('populationPerSquareMileRank');
        } else {
            $this->populationPerSquareMileRank = $populationPerSquareMileRank;
        }
        if ($this->availability('instructionExpensePerWadm') === DataAvailability::NotCollected) {
            $this->markUnavailable('instructionExpensePerWadm');
        } else {
            $this->instructionExpensePerWadm = $instructionExpensePerWadm;
        }
        if ($this->availability('instructionExpensePerWadmRank') === DataAvailability::NotCollected) {
            $this->markUnavailable('instructionExpensePerWadmRank');
        } else {
            $this->instructionExpensePerWadmRank = $instructionExpensePerWadmRank;
        }
        if ($this->availability('totalExpenditurePerAdm') === DataAvailability::NotCollected) {
            $this->markUnavailable('totalExpenditurePerAdm');
        } else {
            $this->totalExpenditurePerAdm = $totalExpenditurePerAdm;
        }
        if ($this->availability('totalExpenditurePerAdmRank') === DataAvailability::NotCollected) {
            $this->markUnavailable('totalExpenditurePerAdmRank');
        } else {
            $this->totalExpenditurePerAdmRank = $totalExpenditurePerAdmRank;
        }
    }
}
