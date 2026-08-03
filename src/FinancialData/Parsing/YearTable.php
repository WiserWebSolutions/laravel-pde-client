<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Parsing;

/**
 * The normalized result of parsing one measure (budget or actual), one
 * category, one fiscal year out of a PDE workbook - regardless of whether
 * the source sheet was wide GFB-style or an AFR detail tab. Everything is
 * plain arrays so instances survive any Laravel cache driver round-trip
 * (see AbstractHtmlFinder for the incident that taught us that).
 */
final class YearTable
{
    /**
     * @param  array<string, array{name: ?string, county: ?string}>  $districts  keyed by AUN
     * @param  array<string, array<string, float>>  $amounts  [aun => [accountCode => amount]]
     * @param  array<string, ?string>  $accountNames  [accountCode => human label]
     */
    public function __construct(
        public readonly array $districts,
        public readonly array $amounts,
        public readonly array $accountNames,
    ) {
    }

    public function toArray(): array
    {
        return [
            'districts' => $this->districts,
            'amounts' => $this->amounts,
            'account_names' => $this->accountNames,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['districts'], $data['amounts'], $data['account_names']);
    }
}
