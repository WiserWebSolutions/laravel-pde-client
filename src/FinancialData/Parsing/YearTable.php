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
     * @param  array<array-key, array{name: ?string, county: ?string}>  $districts  keyed by AUN
     * @param  array<array-key, array<array-key, float|null>>  $amounts  [aun => [accountCode => amount]]
     * @param  array<array-key, ?string>  $accountNames  [accountCode => human label]
     */
    public function __construct(
        public readonly array $districts,
        public readonly array $amounts,
        public readonly array $accountNames,
        public readonly array $availability = [],
        public readonly ?string $source = null,
    ) {}

    public function toArray(): array
    {
        return [
            'districts' => $this->districts,
            'amounts' => $this->amounts,
            'account_names' => $this->accountNames,
            'availability' => $this->availability,
            'source' => $this->source,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['districts'], $data['amounts'], $data['account_names'], $data['availability'] ?? [], $data['source'] ?? null);
    }
}
