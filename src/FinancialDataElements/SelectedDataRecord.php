<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

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
}
