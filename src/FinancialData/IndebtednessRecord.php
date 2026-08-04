<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Enums\DebtPhase;
use WiserWebSolutions\PDEClient\Enums\FundType;

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
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $fiscalYear,
        public readonly FundType $fundType,
        public readonly DebtPhase $phase,
        public readonly ?float $total,
        public readonly array $categories,
    ) {
    }
}
