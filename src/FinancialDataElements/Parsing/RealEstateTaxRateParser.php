<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses a Real Estate Tax Rates workbook into a RowTable with one row per
 * millage line - a district spanning more than one county publishes one row
 * per county, and some counties further split the rate by assessment type
 * (e.g. "Buildings"/"Land"). The 4th column ("Municipality / Other Info") is
 * genuinely mixed-purpose in PDE's own data - it holds real municipality/
 * township names, an assessment-type split, an "Oil/Gas/Mineral Properties"
 * carve-out, or a fiscal-year note ("2025 Calendar Year") depending on the
 * row - so it's kept verbatim as a single nullable `notes` field rather than
 * being forced into a municipality-only column.
 */
class RealEstateTaxRateParser
{
    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function parse(string $path): RowTable
    {
        $columns = null;
        $districts = [];
        $rows = [];

        foreach ($this->reader->sheetNames($path) as $sheet) {
            foreach ($this->reader->rows($path, $sheet) as $row) {
                if ($columns === null) {
                    $normalized = array_map($this->normalize(...), $row);

                    if (! in_array('AUN', $normalized, true)) {
                        continue;
                    }

                    $columns = $this->classifyColumns($normalized, $path);

                    continue;
                }

                $aun = trim((string) ($row[$columns['aun']] ?? ''));

                if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                    continue;
                }

                $districts[$aun] ??= [
                    'name' => $columns['name'] !== null ? (trim((string) ($row[$columns['name']] ?? '')) ?: null) : null,
                ];

                $rows[$aun][] = [
                    'county' => $columns['county'] !== null ? (trim((string) ($row[$columns['county']] ?? '')) ?: null) : null,
                    'notes' => $columns['notes'] !== null ? (trim((string) ($row[$columns['notes']] ?? '')) ?: null) : null,
                    'mills' => $this->numeric($row[$columns['mills']] ?? null),
                    'community_college_mills' => $columns['cc_mills'] !== null
                        ? $this->numeric($row[$columns['cc_mills']] ?? null)
                        : null,
                ];
            }

            if ($columns !== null) {
                break;
            }
        }

        if ($columns === null) {
            throw new PDEClientException("No sheet in [{$path}] has a header row with an AUN column.");
        }

        return new RowTable($districts, $rows);
    }

    /**
     * @param  list<string>  $normalized
     * @return array<string, ?int>
     */
    private function classifyColumns(array $normalized, string $path): array
    {
        $columns = ['aun' => null, 'name' => null, 'county' => null, 'notes' => null, 'mills' => null, 'cc_mills' => null];

        foreach ($normalized as $i => $label) {
            match (true) {
                $label === 'AUN' => $columns['aun'] = $i,
                $label === 'SCHOOLDISTRICT' => $columns['name'] = $i,
                str_contains($label, 'COUNTY') => $columns['county'] = $i,
                str_contains($label, 'MUNICIPALITY') => $columns['notes'] = $i,
                str_contains($label, 'COMMUNITYCOLLEGE') => $columns['cc_mills'] = $i,
                str_contains($label, 'MILLS') || str_contains($label, 'MILLAGE') => $columns['mills'] = $i,
                default => null,
            };
        }

        if ($columns['aun'] === null || $columns['mills'] === null) {
            throw new PDEClientException(
                "Real estate tax rate sheet in [{$path}] is missing the AUN or millage column."
            );
        }

        return $columns;
    }

    private function normalize(mixed $cell): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $cell));
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
