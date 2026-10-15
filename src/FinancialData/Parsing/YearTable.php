<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\SourceReference;

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
        public readonly ?DataProvenance $provenance = null,
        public readonly array $amountSources = [],
        public readonly array $districtSources = [],
        public readonly array $nameSources = [],
        public readonly array $codeSources = [],
    ) {}

    /** @return list<SourceReference> */
    public function sourceReferences(): array
    {
        return ($this->provenance ?? DataProvenance::forPath($this->source ?? ''))->sources;
    }

    /** @return list<SourceReference> */
    public function sourcesForAmount(string $aun, string $code): array
    {
        return $this->references($this->amountSources[$aun][$code] ?? $this->codeSources[$code] ?? null);
    }

    /** @return list<SourceReference> */
    public function sourcesForDistrict(string $aun): array
    {
        return $this->references($this->districtSources[$aun] ?? null);
    }

    /** @return list<SourceReference> */
    public function sourcesForName(string $code): array
    {
        return $this->references($this->nameSources[$code] ?? null);
    }

    private function references(?array $ids): array
    {
        $sources = $this->sourceReferences();

        return $ids === null ? $sources : array_values(array_filter($sources,
            fn (SourceReference $source) => in_array($source->id, $ids, true)));
    }

    public function toArray(): array
    {
        return [
            'districts' => $this->districts,
            'amounts' => $this->amounts,
            'account_names' => $this->accountNames,
            'availability' => $this->availability,
            'source' => $this->source,
            'provenance' => $this->provenance?->toCacheArray(),
            'amount_sources' => $this->amountSources,
            'district_sources' => $this->districtSources,
            'name_sources' => $this->nameSources,
            'code_sources' => $this->codeSources,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['districts'], $data['amounts'], $data['account_names'], $data['availability'] ?? [], $data['source'] ?? null,
            isset($data['provenance']) ? DataProvenance::fromArray($data['provenance']) : null,
            $data['amount_sources'] ?? [], $data['district_sources'] ?? [], $data['name_sources'] ?? [], $data['code_sources'] ?? []);
    }
}
