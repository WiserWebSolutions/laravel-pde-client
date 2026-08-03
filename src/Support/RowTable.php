<?php

namespace WiserWebSolutions\PDEClient\Support;

/**
 * The normalized result of parsing one workbook (or one year of one) whose
 * data is a list of associative rows per LEA, rather than the single
 * code=>amount map that FinancialData\Parsing\YearTable models - the shape
 * shared by the assessment, graduation, and personnel datasets.
 *
 * Everything is plain arrays so instances survive any Laravel cache driver
 * round-trip (see AbstractHtmlFinder for the incident that taught us that).
 */
final class RowTable
{
    /**
     * @param  array<string, array<string, ?string>>  $districts  meta per AUN (name, county, lea_type, ...)
     * @param  array<string, list<array<string, mixed>>>  $rows  [aun => list of associative rows]
     */
    public function __construct(
        public readonly array $districts,
        public readonly array $rows,
    ) {
    }

    public function toArray(): array
    {
        return [
            'districts' => $this->districts,
            'rows' => $this->rows,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['districts'], $data['rows']);
    }
}
