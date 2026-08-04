<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses one fiscal-year tab of the AFR "General Fund Balance" workbook into
 * a RowTable with one row per LEA - the year-end committed/assigned/
 * unassigned general fund balance (account codes 0830/0840/0850), a distinct
 * dataset from FinancialQuery's fundBalances() (the GFB's *beginning*-of-year
 * budgeted 08xx codes, sourced from a different workbook entirely).
 *
 * Same one-sheet-per-year shape as AfrDetailWorkbookParser
 * (availableYears() is identical); a single small parser rather than sharing
 * code, since the column shape here (three flat balance columns, no
 * account-coded header) is unrelated to that parser's account-code
 * extraction.
 */
class FundBalanceParser
{
    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    /**
     * @return list<FiscalYear> years available in this workbook, newest first
     */
    public function availableYears(string $path): array
    {
        $years = [];

        foreach ($this->reader->sheetNames($path) as $name) {
            if (preg_match('/^\d{4}-\d{2}$/', trim($name))) {
                $years[] = FiscalYear::parse($name);
            }
        }

        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return $years;
    }

    public function parseYear(string $path, FiscalYear $year): RowTable
    {
        $columns = null;
        $districts = [];
        $rows = [];

        foreach ($this->reader->rows($path, $year->short()) as $row) {
            if ($columns === null) {
                $normalized = array_map($this->normalize(...), $row);

                if (! in_array('AUN', $normalized, true)) {
                    continue;
                }

                $columns = $this->classifyColumns($normalized, $path, $year);

                continue;
            }

            $aun = trim((string) ($row[$columns['aun']] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] = [
                'name' => $columns['name'] !== null ? (trim((string) ($row[$columns['name']] ?? '')) ?: null) : null,
                'county' => $columns['county'] !== null ? (trim((string) ($row[$columns['county']] ?? '')) ?: null) : null,
            ];

            $rows[$aun][] = [
                'committed' => $this->numeric($row[$columns['committed']] ?? null),
                'assigned' => $this->numeric($row[$columns['assigned']] ?? null),
                'unassigned' => $this->numeric($row[$columns['unassigned']] ?? null),
            ];
        }

        if ($columns === null) {
            throw new PDEClientException(
                "General fund balance sheet [{$year->short()}] in [{$path}] has no header row containing AUN."
            );
        }

        return new RowTable($districts, $rows);
    }

    /**
     * @param  list<string>  $normalized
     * @return array<string, ?int>
     */
    private function classifyColumns(array $normalized, string $path, FiscalYear $year): array
    {
        $columns = ['aun' => null, 'name' => null, 'county' => null, 'committed' => null, 'assigned' => null, 'unassigned' => null];

        foreach ($normalized as $i => $label) {
            match (true) {
                $label === 'AUN' => $columns['aun'] = $i,
                $label === 'LEANAME' => $columns['name'] = $i,
                $label === 'COUNTY' => $columns['county'] = $i,
                str_contains($label, 'COMMITTED') => $columns['committed'] = $i,
                str_contains($label, 'ASSIGNED') && ! str_contains($label, 'UNASSIGNED') => $columns['assigned'] = $i,
                str_contains($label, 'UNASSIGNED') => $columns['unassigned'] = $i,
                default => null,
            };
        }

        if ($columns['aun'] === null) {
            throw new PDEClientException(
                "General fund balance sheet [{$year->short()}] in [{$path}] is missing the AUN column."
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
