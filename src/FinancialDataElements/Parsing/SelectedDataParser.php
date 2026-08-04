<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses PDE's "Selected Data" workbook into a RowTable with one row per LEA
 * - a bundle of headline per-district metrics PDE publishes together,
 * including the two "raw" per-pupil expenditure figures this package doesn't
 * compute itself anywhere else: Actual Instruction Expense per WADM, and
 * Total Expenditures per ADM.
 *
 * Most columns are immediately followed by a same-named "Rank" column (that
 * metric's statewide rank) - since every Rank column shares the identical
 * header text 'Rank', a metric's rank can only be identified positionally
 * (the column immediately after it), not by header text alone. WADM is the
 * one metric with no rank column at all.
 *
 * PDE's own aid ratio column is frequently labeled for a *different*,
 * typically later, school year than the rest of the row (e.g. a "2022-23
 * Selected Data" file's aid ratio column headed "2024-25 MV/PI Aid Ratio") -
 * kept as-is under this record's own schoolYear rather than modeled as a
 * separate field, matching PDE's own presentation.
 */
class SelectedDataParser
{
    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function parse(string $path): RowTable
    {
        [$sheet, $headerIndex, $columns] = $this->locateHeader($path);

        $districts = [];
        $rows = [];

        foreach ($this->reader->rows($path, $sheet) as $i => $row) {
            if ($i <= $headerIndex) {
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
                'aid_ratio' => $this->numeric($row[$columns['aid_ratio']] ?? null),
                'aid_ratio_rank' => $this->numeric($row[$columns['aid_ratio_rank']] ?? null),
                'wadm' => $this->numeric($row[$columns['wadm']] ?? null),
                'adm' => $this->numeric($row[$columns['adm']] ?? null),
                'adm_rank' => $this->numeric($row[$columns['adm_rank']] ?? null),
                'equalized_mills' => $this->numeric($row[$columns['equalized_mills']] ?? null),
                'equalized_mills_rank' => $this->numeric($row[$columns['equalized_mills_rank']] ?? null),
                'population_per_square_mile' => $this->numeric($row[$columns['population_per_square_mile']] ?? null),
                'population_per_square_mile_rank' => $this->numeric($row[$columns['population_per_square_mile_rank']] ?? null),
                'instruction_expense_per_wadm' => $this->numeric($row[$columns['instruction_expense_per_wadm']] ?? null),
                'instruction_expense_per_wadm_rank' => $this->numeric($row[$columns['instruction_expense_per_wadm_rank']] ?? null),
                'total_expenditure_per_adm' => $this->numeric($row[$columns['total_expenditure_per_adm']] ?? null),
                'total_expenditure_per_adm_rank' => $this->numeric($row[$columns['total_expenditure_per_adm_rank']] ?? null),
            ];
        }

        if ($districts === []) {
            throw new PDEClientException("Selected Data sheet [{$sheet}] in [{$path}] produced no district rows.");
        }

        return new RowTable($districts, $rows);
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, ?int>}
     */
    private function locateHeader(string $path): array
    {
        foreach ($this->reader->sheetNames($path) as $sheet) {
            foreach ($this->reader->rows($path, $sheet) as $index => $row) {
                if ($index > 10) {
                    break;
                }

                $normalized = array_map($this->normalize(...), $row);
                $aunIndex = array_search('AUN', $normalized, true);

                if ($aunIndex === false) {
                    continue;
                }

                $columns = $this->classifyColumns($normalized, $aunIndex, $path);

                return [$sheet, $index, $columns];
            }
        }

        throw new PDEClientException("No sheet in [{$path}] has a header row with an AUN column.");
    }

    /**
     * @param  list<string>  $normalized
     * @return array<string, ?int>
     */
    private function classifyColumns(array $normalized, int $aunIndex, string $path): array
    {
        $columns = ['aun' => $aunIndex, 'name' => null, 'county' => null,
            'aid_ratio' => null, 'aid_ratio_rank' => null,
            'wadm' => null,
            'adm' => null, 'adm_rank' => null,
            'equalized_mills' => null, 'equalized_mills_rank' => null,
            'population_per_square_mile' => null, 'population_per_square_mile_rank' => null,
            'instruction_expense_per_wadm' => null, 'instruction_expense_per_wadm_rank' => null,
            'total_expenditure_per_adm' => null, 'total_expenditure_per_adm_rank' => null,
        ];

        $count = count($normalized);

        for ($i = 0; $i < $count; $i++) {
            if ($i === $aunIndex) {
                continue;
            }

            $label = $normalized[$i];
            $nextIsRank = ($normalized[$i + 1] ?? '') === 'RANK';

            $metric = match (true) {
                $label === 'SCHOOLDISTRICT' => 'name',
                $label === 'COUNTY' => 'county',
                str_contains($label, 'AIDRATIO') => 'aid_ratio',
                str_contains($label, 'AIE') && str_contains($label, 'WADM') => 'instruction_expense_per_wadm',
                $label === 'WADM' => 'wadm',
                $label === 'ADM' => 'adm',
                str_contains($label, 'EQMILLS') => 'equalized_mills',
                str_contains($label, 'POPPERSQMILE') => 'population_per_square_mile',
                str_contains($label, 'EXP') && str_contains($label, 'PERADM') => 'total_expenditure_per_adm',
                default => null,
            };

            if ($metric === null) {
                continue;
            }

            $columns[$metric] = $i;

            if ($nextIsRank && array_key_exists("{$metric}_rank", $columns)) {
                $columns["{$metric}_rank"] = $i + 1;
            }
        }

        if ($columns['aun'] === null || $columns['instruction_expense_per_wadm'] === null || $columns['total_expenditure_per_adm'] === null) {
            throw new PDEClientException(
                "Selected Data header row in [{$path}] is missing the AUN or per-pupil expenditure columns."
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
