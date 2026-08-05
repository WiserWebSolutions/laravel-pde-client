<?php

namespace WiserWebSolutions\PDEClient\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;

/**
 * Parses the per-school English learner counts by grade into a district-
 * level YearTable of raw grade-column counts, aggregating school rows to
 * their AUN (this workbook has no LEA-level sheet the way public enrollment
 * does).
 *
 * The sheet name carries a trailing "_N" that isn't stable across years
 * (e.g. "By LEA School and Grade_6"), so it's located by a name pattern
 * instead of an exact match. The 2021-2022 workbook also punctuates it
 * differently ("By LEA, School and Grade_6"), so the comma after "LEA" is
 * optional. Per-school rows are followed by a "<name> Total" pseudo-row
 * with a non-numeric AUN cell and formula-string totals - both are skipped
 * naturally (AUN must be 9 digits; only numeric cells are summed).
 */
class EnglishLearnersParser
{
    private const SHEET_NAME_PATTERN = '/By LEA,?\s*School and Grade/';

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function parse(string $path): YearTable
    {
        $sheet = $this->resolveSheetName($path);
        $rows = $this->reader->rows($path, $sheet);

        $header = null;
        $aunIndex = $nameIndex = null;
        $gradeColumns = [];
        $districts = [];
        $amounts = [];

        foreach ($rows as $row) {
            if ($header === null) {
                if (! in_array('AUN', array_map(fn ($cell) => trim((string) $cell), $row), true)) {
                    continue;
                }

                $header = $row;

                foreach ($header as $index => $label) {
                    $label = trim((string) $label);

                    match (true) {
                        $label === 'AUN' => $aunIndex = $index,
                        $label === 'LEA Name' => $nameIndex = $index,
                        in_array($label, ['', 'Total', 'School Number', 'School Name'], true) => null,
                        default => $gradeColumns[$index] = $label,
                    };
                }

                if ($aunIndex === null || $gradeColumns === []) {
                    throw new PDEClientException(
                        "English learners sheet [{$sheet}] in [{$path}] does not have the expected AUN/grade columns."
                    );
                }

                continue;
            }

            $aun = trim((string) ($row[$aunIndex] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] ??= [
                'name' => $nameIndex !== null ? (trim((string) ($row[$nameIndex] ?? '')) ?: null) : null,
                'county' => null,
                'lea_type' => null,
            ];

            foreach ($gradeColumns as $index => $code) {
                $value = $row[$index] ?? null;

                if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
                    continue;
                }

                $amounts[$aun][$code] = ($amounts[$aun][$code] ?? 0.0) + (float) $value;
            }
        }

        if ($header === null) {
            throw new PDEClientException(
                "English learners sheet [{$sheet}] in [{$path}] has no header row containing AUN."
            );
        }

        return new YearTable($districts, $amounts, array_fill_keys(array_values($gradeColumns), null));
    }

    private function resolveSheetName(string $path): string
    {
        foreach ($this->reader->sheetNames($path) as $name) {
            if (preg_match(self::SHEET_NAME_PATTERN, $name) === 1) {
                return $name;
            }
        }

        throw new PDEClientException(
            "No sheet matching [".self::SHEET_NAME_PATTERN."] found in [{$path}]."
        );
    }
}
