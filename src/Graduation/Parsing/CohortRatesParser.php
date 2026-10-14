<?php

namespace WiserWebSolutions\PDEClient\Graduation\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\Support\ReportAvailability;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Support\SpreadsheetReader;

/**
 * Parses the "Grad Rate by LEA" sheet of a cohort graduation rate workbook
 * into a RowTable, melting PDE's wide one-row-per-LEA layout into one row
 * per student group.
 *
 * The Total group gets graduate and cohort counts alongside its rate; every
 * demographic group column ("Male Grad Rate", "EL\nGrad Rate", ...) carries
 * a rate only - that's all PDE publishes for them. Rates are fractions
 * (0-1) exactly as stored in the workbook, and null where PDE left the cell
 * empty (group too small or not present).
 *
 * Recognized older templates use a different header generation ("Total Grads" /
 * "Total Cohort" / "Total Grad Rate" instead of "Grads" / "Cohort" /
 * "Cohort Grad Rate") and store their numbers as text cells; both variants
 * are matched, and text numbers are cast. The old "Total Grad Rate" label
 * would otherwise be swallowed by the "<group> Grad Rate" melt as a bogus
 * "Total" group, which is why the explicit arms come before the suffix match.
 */
class CohortRatesParser
{
    private const SHEET_FRAGMENT = 'Grad Rate by LEA';

    private const GROUP_COLUMN_SUFFIX = 'Grad Rate';

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function parse(string $path): RowTable
    {
        $availability = new ReportAvailability($path);
        $sheet = $this->resolveSheetName($path);

        $header = null;
        $aunIndex = $nameIndex = $typeIndex = $gradsIndex = $cohortIndex = $totalRateIndex = null;
        $groupColumns = [];
        $districts = [];
        $rows = [];

        foreach ($this->reader->rows($path, $sheet) as $row) {
            if ($header === null) {
                $labels = array_map(fn ($cell) => preg_replace('/\s+/', ' ', trim((string) $cell)), $row);

                if (! in_array('AUN', $labels, true)) {
                    continue;
                }

                $header = $labels;

                foreach ($labels as $index => $label) {
                    match (true) {
                        $label === 'AUN' => $aunIndex = $index,
                        $label === 'LEA' => $nameIndex = $index,
                        $label === 'LEA Type' => $typeIndex = $index,
                        $label === 'Grads', $label === 'Total Grads' => $gradsIndex = $index,
                        $label === 'Cohort', $label === 'Total Cohort' => $cohortIndex = $index,
                        $label === 'Cohort Grad Rate', $label === 'Total Grad Rate' => $totalRateIndex = $index,
                        str_ends_with($label, self::GROUP_COLUMN_SUFFIX) && $label !== self::GROUP_COLUMN_SUFFIX => $groupColumns[$index] = trim(substr($label, 0, -strlen(self::GROUP_COLUMN_SUFFIX))),
                        default => null,
                    };
                }

                if ($aunIndex === null || $totalRateIndex === null) {
                    throw new PDEClientException(
                        "Cohort sheet [{$sheet}] in [{$path}] does not have the expected AUN/rate columns."
                    );
                }

                $availability->define(['lea_name' => $nameIndex, 'lea_type' => $typeIndex]);
                $availability->define(['graduates' => $gradsIndex, 'cohort_size' => $cohortIndex, 'rate' => $totalRateIndex], 'Total');
                $availability->groups(['Total', ...array_values($groupColumns)], true);
                foreach ($groupColumns as $index => $group) {
                    $availability->states(['graduates' => 'not_applicable', 'cohort_size' => 'not_applicable', 'rate' => 'available'], $group);
                }

                continue;
            }

            $aun = trim((string) ($row[$aunIndex] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] = [
                'name' => $nameIndex !== null ? (trim((string) ($row[$nameIndex] ?? '')) ?: null) : null,
                'county' => null,
                'lea_type' => $typeIndex !== null ? (trim((string) ($row[$typeIndex] ?? '')) ?: null) : null,
            ];

            $availability->observe($aun, count($rows[$aun] ?? []), $row, ['graduates' => $gradsIndex, 'cohort_size' => $cohortIndex, 'rate' => $totalRateIndex]);
            $rows[$aun][] = [
                'group' => 'Total',
                'graduates' => $this->numeric($gradsIndex !== null ? ($row[$gradsIndex] ?? null) : null),
                'cohort_size' => $this->numeric($cohortIndex !== null ? ($row[$cohortIndex] ?? null) : null),
                'rate' => $this->numeric($row[$totalRateIndex] ?? null),
            ];

            foreach ($groupColumns as $index => $group) {
                $availability->observe($aun, count($rows[$aun]), $row, ['rate' => $index]);
                $rows[$aun][] = [
                    'group' => $group,
                    'graduates' => null,
                    'cohort_size' => null,
                    'rate' => $this->numeric($row[$index] ?? null),
                ];
            }
        }

        if ($header === null) {
            throw new PDEClientException(
                "Cohort sheet [{$sheet}] in [{$path}] has no header row containing AUN."
            );
        }

        return $availability->table($districts, $rows);
    }

    private function resolveSheetName(string $path): string
    {
        foreach ($this->reader->sheetNames($path) as $name) {
            if (str_contains($name, self::SHEET_FRAGMENT)) {
                return $name;
            }
        }

        throw new PDEClientException(
            'No sheet matching ['.self::SHEET_FRAGMENT."] found in [{$path}]."
        );
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
