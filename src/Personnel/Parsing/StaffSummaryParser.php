<?php

namespace WiserWebSolutions\PDEClient\Personnel\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses the "LEA_Averages" sheet (full-time staff) of a professional staff
 * summary workbook into a RowTable with one row per staff category per LEA.
 *
 * PDE's header names the same category with drifting case and at least one
 * typo across column families ("CT", "Sal-CT", "Svc-Ct", "Svcv-Ot"), so
 * headers are normalized to uppercase alphanumerics before matching, with an
 * alias for the known typo. Older workbooks may lack some of the average
 * columns; those come through as null rather than failing the parse - only
 * AUN and the per-category headcounts are required.
 */
class StaffSummaryParser
{
    private const SHEET = 'LEA_Averages';

    /** Category column prefix => semantic category name. */
    private const CATEGORIES = [
        'PP' => 'professional',
        'AD' => 'administrator',
        'CT' => 'classroom_teacher',
        'CO' => 'coordinator',
        'OT' => 'other',
    ];

    /** Known header typos, normalized-form => corrected normalized form. */
    private const HEADER_ALIASES = [
        'SVCVOT' => 'SVCOT',
    ];

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function parse(string $path): RowTable
    {
        $positions = null;
        $meta = null;
        $districts = [];
        $rows = [];

        foreach ($this->reader->rows($path, self::SHEET) as $row) {
            if ($positions === null) {
                $normalized = array_map($this->normalizeHeader(...), $row);

                if (! in_array('AUN', $normalized, true)) {
                    continue;
                }

                [$positions, $meta] = $this->mapColumns($normalized, $path);

                continue;
            }

            $aun = trim((string) ($row[$meta['aun']] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] = [
                'name' => $meta['name'] !== null ? (trim((string) ($row[$meta['name']] ?? '')) ?: null) : null,
                'county' => $meta['county'] !== null ? (trim((string) ($row[$meta['county']] ?? '')) ?: null) : null,
                'lea_type' => $meta['type'] !== null ? (trim((string) ($row[$meta['type']] ?? '')) ?: null) : null,
            ];

            foreach (self::CATEGORIES as $prefix => $category) {
                $columns = $positions[$prefix];

                $rows[$aun][] = [
                    'category' => $category,
                    'count' => $this->numericAt($row, $columns['count']),
                    'female' => $this->numericAt($row, $columns['female']),
                    'male' => $this->numericAt($row, $columns['male']),
                    'salary' => $this->numericAt($row, $columns['salary']),
                    'service' => $this->numericAt($row, $columns['service']),
                    'lea_years' => $this->numericAt($row, $columns['lea_years']),
                    'education' => $this->numericAt($row, $columns['education']),
                ];
            }
        }

        if ($positions === null) {
            throw new PDEClientException(
                'Staff summary sheet ['.self::SHEET."] in [{$path}] has no header row containing AUN."
            );
        }

        return new RowTable($districts, $rows);
    }

    /**
     * @param  list<string>  $normalized
     * @return array{0: array<string, array<string, ?int>>, 1: array<string, ?int>}
     */
    private function mapColumns(array $normalized, string $path): array
    {
        $find = fn (string $target): ?int => ($index = array_search($target, $normalized, true)) === false ? null : $index;

        $meta = [
            'aun' => $find('AUN'),
            'name' => $find('LEANAME') ?? $find('LEA'),
            'type' => $find('LEATYPE'),
            'county' => $find('COUNTY'),
        ];

        $positions = [];

        foreach (array_keys(self::CATEGORIES) as $prefix) {
            $positions[$prefix] = [
                'count' => $find($prefix),
                'female' => $find("{$prefix}F"),
                'male' => $find("{$prefix}M"),
                'salary' => $find("SAL{$prefix}"),
                'service' => $find("SVC{$prefix}"),
                'lea_years' => $find("LEA{$prefix}"),
                'education' => $find("ED{$prefix}"),
            ];

            if ($positions[$prefix]['count'] === null) {
                throw new PDEClientException(
                    "Staff summary sheet in [{$path}] is missing the [{$prefix}] headcount column."
                );
            }
        }

        return [$positions, $meta];
    }

    private function normalizeHeader(mixed $cell): string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $cell));

        return self::HEADER_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function numericAt(array $row, ?int $index): ?float
    {
        if ($index === null) {
            return null;
        }

        $value = $row[$index] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
