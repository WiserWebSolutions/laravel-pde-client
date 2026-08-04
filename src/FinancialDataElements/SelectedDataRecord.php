<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Illuminate\Contracts\Support\Arrayable;

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
final class SelectedDataRecord implements Arrayable
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly ?float $aidRatio,
        public readonly ?float $aidRatioRank,
        public readonly ?float $wadm,
        public readonly ?float $adm,
        public readonly ?float $admRank,
        public readonly ?float $equalizedMills,
        public readonly ?float $equalizedMillsRank,
        public readonly ?float $populationPerSquareMile,
        public readonly ?float $populationPerSquareMileRank,
        public readonly ?float $instructionExpensePerWadm,
        public readonly ?float $instructionExpensePerWadmRank,
        public readonly ?float $totalExpenditurePerAdm,
        public readonly ?float $totalExpenditurePerAdmRank,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'county' => $this->county,
            'school_year' => $this->schoolYear,
            'aid_ratio' => $this->aidRatio,
            'aid_ratio_rank' => $this->aidRatioRank,
            'wadm' => $this->wadm,
            'adm' => $this->adm,
            'adm_rank' => $this->admRank,
            'equalized_mills' => $this->equalizedMills,
            'equalized_mills_rank' => $this->equalizedMillsRank,
            'population_per_square_mile' => $this->populationPerSquareMile,
            'population_per_square_mile_rank' => $this->populationPerSquareMileRank,
            'instruction_expense_per_wadm' => $this->instructionExpensePerWadm,
            'instruction_expense_per_wadm_rank' => $this->instructionExpensePerWadmRank,
            'total_expenditure_per_adm' => $this->totalExpenditurePerAdm,
            'total_expenditure_per_adm_rank' => $this->totalExpenditurePerAdmRank,
        ];
    }
}
