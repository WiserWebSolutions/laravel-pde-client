<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One district's year-end general fund balance breakdown (account codes
 * 0830/0840/0850, from the AFR) for one fiscal year - not to be confused
 * with FinancialQuery::fundBalances(), which covers the GFB's *beginning*-
 * of-year budgeted 08xx codes from an entirely different workbook.
 */
#[MapName(SnakeCaseMapper::class)]
final class FundBalanceRecord extends Data
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $fiscalYear,
        public readonly ?float $committed,
        public readonly ?float $assigned,
        public readonly ?float $unassigned,
    ) {
    }

    /** Sum of whichever of committed/assigned/unassigned are present; null if none are. */
    public function total(): ?float
    {
        $values = array_filter([$this->committed, $this->assigned, $this->unassigned], fn ($v) => $v !== null);

        return $values === [] ? null : round(array_sum($values), 2);
    }
}
