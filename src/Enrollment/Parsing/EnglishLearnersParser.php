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
 * instead of an exact match. Some years also punctuate it differently
 * ("By LEA, School and Grade_6" in 2021-2022, "By LEA,School and Grade_6"
 * in 2017-18 through 2020-21), so the comma after "LEA" is optional and
 * any surrounding whitespace is tolerated. 2016-2017 has no per-grade
 * breakdown sheet at all - the whole workbook is a single "LEP Students by
 * School" sheet with one school-level total column instead of grade
 * columns - so that single sheet is used as a fallback; the resulting
 * "grade" bucket is a non-grade label ('LEP Students') rather than PK/K/1-
 * 12, but that's fine since only the district-level sum of counts is used.
 * Per-school rows are followed by a "<name> Total" pseudo-row with a non-
 * numeric AUN cell and formula-string totals - both are skipped naturally
 * (AUN must be 9 digits; only numeric cells are summed).
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
        $sheets = $this->reader->sheetNames($path);

        foreach ($sheets as $name) {
            if (preg_match(self::SHEET_NAME_PATTERN, $name) === 1) {
                return $name;
            }
        }

        if (count($sheets) === 1) {
            return $sheets[0];
        }

        throw new PDEClientException(
            "No sheet matching [".self::SHEET_NAME_PATTERN."] found in [{$path}]."
        );
    }
}
