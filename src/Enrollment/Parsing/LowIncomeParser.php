<?php

namespace WiserWebSolutions\PDEClient\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses PDE's single, in-place-updated "Ten Year Low Income and Enrollment
 * History" workbook. Unlike every other enrollment file (one workbook per
 * school year), this one packs every year into ONE sheet as repeating
 * 3-column groups (Low Income count / Enrollment / Low Income %) - a
 * category super-header ("2016-2017") merge-celled across its own group's 3
 * columns, the same pattern as IndebtednessParser's fund-type/phase groups,
 * so the super-header is forward-filled the same way before classifying each
 * column.
 */
class LowIncomeParser
{
    private const SHEET_NAME_FRAGMENT = 'Low Income';

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableYears(string $path): array
    {
        [, , $yearColumns] = $this->locateHeader($path);

        $years = array_map(fn (string $year) => FiscalYear::parse($year), array_keys($yearColumns));
        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return $years;
    }

    public function parseYear(string $path, FiscalYear $year): RowTable
    {
        [$sheet, $headerIndex, $yearColumns, $meta] = $this->locateHeader($path);

        $columns = $yearColumns[$year->long()] ?? null;

        if ($columns === null) {
            throw new PDEClientException(
                "Low income sheet [{$sheet}] in [{$path}] has no column group for [{$year->long()}]."
            );
        }

        $districts = [];
        $rows = [];

        foreach ($this->reader->rows($path, $sheet) as $i => $row) {
            if ($i <= $headerIndex) {
                continue;
            }

            $aun = trim((string) ($row[$meta['aun']] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] = [
                'name' => $meta['name'] !== null ? (trim((string) ($row[$meta['name']] ?? '')) ?: null) : null,
                'lea_type' => $meta['type'] !== null ? (trim((string) ($row[$meta['type']] ?? '')) ?: null) : null,
                'county' => $meta['county'] !== null ? (trim((string) ($row[$meta['county']] ?? '')) ?: null) : null,
            ];

            $rows[$aun][] = [
                'low_income' => $this->numeric($row[$columns['low_income']] ?? null),
                'enrollment' => $this->numeric($row[$columns['enrollment']] ?? null),
                'percent' => $this->numeric($row[$columns['percent']] ?? null),
            ];
        }

        return new RowTable($districts, $rows);
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, array<string, int>>, 3: array<string, ?int>}
     */
    private function locateHeader(string $path): array
    {
        foreach ($this->reader->sheetNames($path) as $sheet) {
            $previousRow = null;

            foreach ($this->reader->rows($path, $sheet) as $index => $row) {
                if ($index > 10) {
                    break;
                }

                $normalized = array_map(fn ($cell) => strtoupper(trim((string) $cell)), $row);
                $aunIndex = array_search('AUN', $normalized, true);

                if ($aunIndex === false) {
                    $previousRow = $row;

                    continue;
                }

                $meta = [
                    'aun' => $aunIndex,
                    'name' => $this->findColumn($normalized, 'LEA'),
                    'type' => $this->findColumn($normalized, 'TYPE'),
                    'county' => $this->findColumn($normalized, 'COUNTY'),
                ];

                $yearColumns = $this->classifyYearColumns($previousRow ?? array_fill(0, count($row), ''), $row);

                if ($yearColumns === []) {
                    throw new PDEClientException(
                        "Low income sheet [{$sheet}] in [{$path}] has an AUN header row but no year column groups."
                    );
                }

                return [$sheet, $index, $yearColumns, $meta];
            }
        }

        throw new PDEClientException("No sheet in [{$path}] has a header row with an AUN column.");
    }

    /**
     * @param  list<mixed>  $superHeader
     * @param  list<mixed>  $subHeader
     * @return array<string, array<string, int>> long-year => {low_income, enrollment, percent} => column index
     */
    private function classifyYearColumns(array $superHeader, array $subHeader): array
    {
        $filledSuper = $this->forwardFill($superHeader);
        $years = [];

        foreach ($subHeader as $i => $cell) {
            $label = strtoupper(trim((string) $cell));

            if ($label === '') {
                continue;
            }

            $yearLabel = trim((string) ($filledSuper[$i] ?? ''));

            if (! preg_match('/^\d{4}-\d{4}$/', $yearLabel)) {
                continue;
            }

            $key = null;

            if (str_contains($label, 'PERCENT') || str_contains($label, '%')) {
                $key = 'percent';
            } elseif (str_contains($label, 'ENROLLMENT')) {
                $key = 'enrollment';
            } elseif (str_contains($label, 'INCOME')) {
                $key = 'low_income';
            }

            if ($key !== null) {
                $years[$yearLabel][$key] = $i;
            }
        }

        return array_filter($years, fn (array $columns) => count($columns) === 3);
    }

    /**
     * @param  list<mixed>  $row
     * @return array<int, string>
     */
    private function forwardFill(array $row): array
    {
        $filled = [];
        $lastSeen = '';

        foreach ($row as $i => $cell) {
            $cell = trim((string) $cell);

            if ($cell !== '') {
                $lastSeen = $cell;
            }

            $filled[$i] = $lastSeen;
        }

        return $filled;
    }

    /**
     * @param  list<string>  $normalized
     */
    private function findColumn(array $normalized, string $needle): ?int
    {
        foreach ($normalized as $i => $label) {
            if ($label !== '' && str_contains($label, $needle)) {
                return $i;
            }
        }

        return null;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
