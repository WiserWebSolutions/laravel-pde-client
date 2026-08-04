<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses one fiscal-year tab of the AFR "Short- and Long-Term Debt"
 * (Statement of Indebtedness) workbook into a RowTable with, per LEA, one
 * row per (fund type, phase) combination: fund type is 'governmental',
 * 'proprietary', or 'all' (PDE's own top-level beginning/end summary,
 * unbroken down by fund type); phase is 'beginning', 'additional',
 * 'retirements', or 'end' ('all' only ever has 'beginning'/'end').
 *
 * The header spans two rows - a category super-header ("Governmental Fund
 * Types -- Debt Outstanding at Beginning of Fiscal Year") merge-celled
 * across 8 sub-columns (7 debt categories + TOTAL) in the row beneath it,
 * repeated for all 8 fund-type/phase combinations. Like DropoutsParser's
 * 2007-08 file, a merged cell's text is stored only in its first column, so
 * the super-header is forward-filled before classifying each column - safe
 * here (unlike DropoutsParser) because every column from the fund-type
 * section onward genuinely belongs to some group, so there's no unrelated
 * meta column a forward-filled label could bleed into.
 *
 * The specific debt categories PDE breaks TOTAL down into have changed
 * across years (2015-16: Other Long-Term Debt / OPEB / Compensated Absences
 * / Net Pension Liability as four separate columns; 2024-25 onward:
 * consolidated into fewer, differently-named categories, plus new Leases and
 * Extended Term Financing Agreements columns) - a real reporting methodology
 * change, not just cosmetic header drift, so category names are kept
 * verbatim per year in `categories` rather than forced into a single
 * cross-year taxonomy.
 *
 * Every "TOTAL" column, and both "All Fund Types" columns, are unevaluated
 * spreadsheet formulas (e.g. `=SUM(G3:M3)`, `=N3+AT3`) with no cached result
 * for the reader to fall back to - this workbook was evidently generated
 * without ever being opened in Excel to compute them. `total` is therefore
 * computed here instead, the same way FinancialQuery computes its own
 * account-code rollups rather than trusting a source-reported total: each
 * governmental/proprietary group's total is the sum of its own category
 * values, and each "all" total is the matching governmental + proprietary
 * total for that phase (exactly what the sheet's own `=N+AT`-style formula
 * would have computed).
 */
class IndebtednessParser
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
        $meta = null;
        $groups = null;
        $previousRow = null;
        $districts = [];
        $rows = [];

        foreach ($this->reader->rows($path, $year->short()) as $row) {
            if ($meta === null) {
                $normalized = array_map(fn ($cell) => strtoupper(trim((string) $cell)), $row);
                $find = fn (string $target): ?int => ($index = array_search($target, $normalized, true)) === false ? null : $index;

                $aunIndex = $find('AUN');

                if ($aunIndex === null) {
                    $previousRow = $row;

                    continue;
                }

                $meta = ['aun' => $aunIndex, 'name' => $find('LEA NAME'), 'county' => $find('COUNTY')];
                $groups = $this->classifyColumns($previousRow ?? array_fill(0, count($row), ''), $row, $aunIndex);

                if ($groups === []) {
                    throw new PDEClientException(
                        "Statement of Indebtedness sheet [{$year->short()}] in [{$path}] has an AUN header row but no fund-type/phase columns."
                    );
                }

                continue;
            }

            $aun = trim((string) ($row[$meta['aun']] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] = [
                'name' => $meta['name'] !== null ? (trim((string) ($row[$meta['name']] ?? '')) ?: null) : null,
                'county' => $meta['county'] !== null ? (trim((string) ($row[$meta['county']] ?? '')) ?: null) : null,
            ];

            $totals = ['governmental' => [], 'proprietary' => []];
            $groupRows = [];

            foreach ($groups as $key => $group) {
                [$fundType, $phase] = explode('|', $key);

                if ($fundType === 'all') {
                    continue;
                }

                $categories = [];

                foreach ($group['categories'] as $label => $index) {
                    $value = $this->numeric($row[$index] ?? null);

                    if ($value !== null) {
                        $categories[$label] = $value;
                    }
                }

                $total = $categories === [] ? null : round(array_sum($categories), 2);
                $totals[$fundType][$phase] = $total;

                $groupRows[] = ['fund_type' => $fundType, 'phase' => $phase, 'total' => $total, 'categories' => $categories];
            }

            foreach (['beginning', 'end'] as $phase) {
                $govTotal = $totals['governmental'][$phase] ?? null;
                $propTotal = $totals['proprietary'][$phase] ?? null;

                $rows[$aun][] = [
                    'fund_type' => 'all',
                    'phase' => $phase,
                    'total' => $govTotal === null && $propTotal === null ? null : round(($govTotal ?? 0.0) + ($propTotal ?? 0.0), 2),
                    'categories' => [],
                ];
            }

            array_push($rows[$aun], ...$groupRows);
        }

        if ($meta === null) {
            throw new PDEClientException(
                "Statement of Indebtedness sheet [{$year->short()}] in [{$path}] has no header row containing AUN."
            );
        }

        return new RowTable($districts, $rows);
    }

    /**
     * The "All Fund Types" super-header (idx4/5) is deliberately skipped
     * here - unlike every other group it has no category breakdown at all,
     * just its own (formula, unreadable) total, which parseYear() computes
     * separately as governmental + proprietary per phase instead.
     *
     * @param  list<mixed>  $superHeader
     * @param  list<mixed>  $subHeader
     * @return array<string, array{categories: array<string, int>}>
     */
    private function classifyColumns(array $superHeader, array $subHeader, int $aunIndex): array
    {
        $filledSuper = $this->forwardFill($superHeader);
        $groups = [];

        foreach ($subHeader as $i => $cell) {
            if ($i === $aunIndex) {
                continue;
            }

            $label = trim((string) $cell);

            if ($label === '' || strtoupper($label) === 'TOTAL') {
                // The TOTAL sub-column is itself an unevaluated formula
                // cell - skipped, since parseYear() computes each group's
                // total from its own category values instead.
                continue;
            }

            $superText = strtoupper((string) preg_replace('/\s+/', ' ', trim((string) ($filledSuper[$i] ?? ''))));

            $fundType = match (true) {
                str_contains($superText, 'GOVERNMENTAL') => 'governmental',
                str_contains($superText, 'PROPRIETARY') => 'proprietary',
                default => null,
            };

            if ($fundType === null) {
                continue;
            }

            $phase = match (true) {
                str_contains($superText, 'ADDITIONAL') => 'additional',
                str_contains($superText, 'RETIREMENT') => 'retirements',
                str_contains($superText, 'BEGINNING') => 'beginning',
                default => 'end',
            };

            $groups["{$fundType}|{$phase}"]['categories'][$label] = $i;
        }

        return $groups;
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

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
