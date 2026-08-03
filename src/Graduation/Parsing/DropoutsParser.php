<?php

namespace WiserWebSolutions\PDEClient\Graduation\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses the per-LEA (or, lacking that, per-school) sheet of a dropout
 * summary workbook into a RowTable with one row per LEA.
 *
 * Both the sheet holding this data and its column headers drift constantly
 * across the years these workbooks span:
 * - Sheet name: "Listing By LEA" (2012-13), "Summary by LEA_N" (an unstable
 *   numeric suffix, ~2017-18 through ~2023-24), "Summary by LEA" (current).
 *   2015-16 has no per-LEA sheet at all - only "Summary by School_5" - so
 *   every sheet is scanned for ANY row with an AUN-ish header, preferring a
 *   sheet whose name doesn't mention "School" (already-aggregated) over one
 *   that does (per-school, requires aggregating here).
 * - Column names: AUN is "AUN" or "SUBMITTING AUN"; the enrollment column
 *   alone has been "Grade 7-12 Enrollments", "Oct 1 Enrollment Grades
 *   7-12", "OCT 1 ENROLLMENT\nGRADES 7-12", and "SCHOOL ENROLLMENT BEFORE
 *   THIS OCT 1". Columns are matched by normalized (uppercased,
 *   non-alphanumeric stripped) fuzzy patterns rather than exact strings.
 *
 * Per-school sheets also merge-cell their AUN/LEA-name/county columns down a
 * district's block of school rows (blank on every row but the first) and
 * add a synthetic "<District> Total" row with those same columns blank -
 * both are handled by carrying the last seen AUN forward onto rows that
 * still have a school code/name (real continuation rows), while treating a
 * blank-AUN row with a blank school code/name too as the synthetic total
 * (skipped, since summing the real school rows already produces that same
 * total here).
 *
 * 2007-08 through 2009-10 additionally split their header across two rows:
 * a category super-header ("DROPOUTS") merge-celled across "MALE"/"FEMALE"/
 * "TOTAL" sub-columns in the row beneath it. @see self::mergeWithRowAbove
 */
class DropoutsParser
{
    private const REQUIRED = ['name', 'enrollment', 'male', 'female', 'dropouts', 'rate'];

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function parse(string $path): RowTable
    {
        [$sheet, $headerIndex, $columns] = $this->locateHeader($path);

        $districts = [];
        $rows = [];
        $currentAun = null;
        $currentMeta = null;

        foreach ($this->reader->rows($path, $sheet) as $i => $row) {
            if ($i <= $headerIndex) {
                continue;
            }

            $aunCell = trim((string) ($row[$columns['aun']] ?? ''));
            $isNewGroup = preg_match('/^\d{9}$/', $aunCell) === 1;

            if ($isNewGroup) {
                $currentAun = $aunCell;
                $currentMeta = [
                    'name' => $this->cell($row, $columns['name']),
                    'county' => $this->cell($row, $columns['county']),
                ];
            } elseif ($columns['school'] !== null && $this->cell($row, $columns['school']) === null) {
                // Blank AUN + blank school = a synthetic per-district total
                // row on an otherwise per-school sheet - skip it, the real
                // school rows already sum to the same total.
                continue;
            }

            if ($currentAun === null) {
                continue;
            }

            $districts[$currentAun] = [
                'name' => $currentMeta['name'],
                'county' => $currentMeta['county'],
                'lea_type' => null,
            ];

            $rows[$currentAun][] = [
                'enrollment' => $this->numeric($row[$columns['enrollment']] ?? null),
                'male_dropouts' => $this->numeric($row[$columns['male']] ?? null),
                'female_dropouts' => $this->numeric($row[$columns['female']] ?? null),
                'dropouts' => $this->numeric($row[$columns['dropouts']] ?? null),
                'rate' => $this->numeric($row[$columns['rate']] ?? null),
            ];
        }

        if ($currentAun === null && $rows === []) {
            throw new PDEClientException(
                "Dropout sheet [{$sheet}] in [{$path}] had a header row but no data rows resolved to an AUN."
            );
        }

        return $this->aggregate($districts, $rows);
    }

    /**
     * Per-school sheets contribute multiple rows per AUN; sum them to one
     * row per district. Sheets that were already per-LEA (the common case)
     * have exactly one row per AUN already, which passes through untouched
     * here rather than being re-derived - PDE's own reported rate is kept
     * exactly rather than recomputed from the (possibly rounded) enrollment
     * and dropout counts.
     */
    private function aggregate(array $districts, array $rows): RowTable
    {
        $aggregated = [];

        foreach ($rows as $aun => $schoolRows) {
            if (count($schoolRows) === 1) {
                $aggregated[$aun] = $schoolRows;

                continue;
            }

            $totals = ['enrollment' => null, 'male_dropouts' => null, 'female_dropouts' => null, 'dropouts' => null];

            foreach ($schoolRows as $row) {
                foreach (['enrollment', 'male_dropouts', 'female_dropouts', 'dropouts'] as $field) {
                    if ($row[$field] !== null) {
                        $totals[$field] = ($totals[$field] ?? 0.0) + $row[$field];
                    }
                }
            }

            $totals['rate'] = ($totals['dropouts'] !== null && $totals['enrollment'] !== null && $totals['enrollment'] > 0)
                ? round($totals['dropouts'] / $totals['enrollment'], 12)
                : null;

            $aggregated[$aun] = [$totals];
        }

        return new RowTable($districts, $aggregated);
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, ?int>}
     */
    private function locateHeader(string $path): array
    {
        $candidates = [];

        foreach ($this->reader->sheetNames($path) as $sheet) {
            $previousRow = null;

            foreach ($this->reader->rows($path, $sheet) as $index => $row) {
                if ($index > 10) {
                    break;
                }

                $normalized = array_map($this->normalize(...), $row);
                $aunIndex = $this->findColumn($normalized, fn (string $n) => str_contains($n, 'AUN'));

                if ($aunIndex === null) {
                    $previousRow = $row;

                    continue;
                }

                $columns = $this->classifyColumns($normalized, $aunIndex);
                $missing = array_keys(array_filter(
                    $columns,
                    fn ($value, $key) => $value === null && in_array($key, self::REQUIRED, true),
                    ARRAY_FILTER_USE_BOTH
                ));

                // Some years (e.g. 2007-08 through 2009-10) split the header
                // across two rows - a category super-header ("DROPOUTS")
                // spanning several sub-columns ("MALE"/"FEMALE"/"TOTAL") on
                // the row beneath it, stored (as with any Excel merged cell)
                // only in the FIRST cell of that span. Whatever fields the
                // row alone couldn't classify are retried against that row
                // merged with the row above (forward-filled across the
                // above row's blanks to recover the spanning label) -
                // fields already resolved from the row alone are left
                // untouched, so this can't clobber e.g. a "LEA NAME" column
                // by merging it with an unrelated "COUNTY" super-header.
                if ($missing !== [] && $previousRow !== null) {
                    $merged = $this->classifyColumns($this->mergeWithRowAbove($row, $previousRow), $aunIndex);

                    foreach ($missing as $key) {
                        $columns[$key] = $merged[$key];
                    }
                }

                if (array_filter($columns, fn ($value, $key) => $value === null && in_array($key, self::REQUIRED, true), ARRAY_FILTER_USE_BOTH) !== []) {
                    $previousRow = $row;

                    continue;
                }

                $candidates[] = [$sheet, $index, $columns];

                break;
            }
        }

        if ($candidates === []) {
            throw new PDEClientException(
                "No sheet in [{$path}] has a header row with an AUN-like column and the expected dropout fields."
            );
        }

        // Prefer an already per-LEA sheet (no school column) over a
        // per-school one, so the common case doesn't pay the aggregation
        // cost or risk of a differently-computed rate.
        usort($candidates, fn (array $a, array $b) => ($a[2]['school'] !== null) <=> ($b[2]['school'] !== null));

        return $candidates[0];
    }

    /**
     * @return list<string> normalized, each cell prefixed with the row above's own cell text
     */
    private function mergeWithRowAbove(array $row, ?array $above): array
    {
        if ($above === null) {
            return array_map($this->normalize(...), $row);
        }

        // A category super-header ("DROPOUTS" spanning MALE/FEMALE/TOTAL)
        // is, like any Excel merged cell, stored only in the first cell of
        // its span - the sibling cells beneath the other sub-columns read
        // back blank. Forward-filling recovers it for those siblings.
        $filled = [];
        $lastSeen = '';

        foreach ($above as $i => $cell) {
            $cell = trim((string) $cell);

            if ($cell !== '') {
                $lastSeen = $cell;
            }

            $filled[$i] = $lastSeen;
        }

        // Only cells with their OWN non-blank label get the above row's
        // text prefixed - otherwise trailing blank columns past the last
        // real sub-column would inherit the forward-filled super-header
        // text and be misclassified as a real (empty) data column.
        $merged = [];

        foreach ($row as $i => $cell) {
            $cell = trim((string) $cell);
            $merged[$i] = $cell === '' ? '' : trim(($filled[$i] ?? '').' '.$cell);
        }

        return array_map($this->normalize(...), $merged);
    }

    /**
     * Classifies as many columns as this row's text alone allows; does not
     * check for completeness - callers combine this with a merged-row
     * retry and check @see self::REQUIRED themselves.
     *
     * @param  list<string>  $normalized
     * @return array<string, ?int>
     */
    private function classifyColumns(array $normalized, int $aunIndex): array
    {
        $columns = ['aun' => $aunIndex, 'name' => null, 'county' => null, 'school' => null,
            'enrollment' => null, 'male' => null, 'female' => null, 'dropouts' => null, 'rate' => null];

        foreach ($normalized as $i => $label) {
            if ($i === $aunIndex || $label === '') {
                continue;
            }

            match (true) {
                str_contains($label, 'ENROLLMENT') => $columns['enrollment'] = $i,
                str_contains($label, 'FEMALE') && str_contains($label, 'DROPOUT') => $columns['female'] = $i,
                str_contains($label, 'MALE') && str_contains($label, 'DROPOUT') => $columns['male'] = $i,
                str_contains($label, 'DROPOUT') && str_contains($label, 'RATE') => $columns['rate'] = $i,
                str_contains($label, 'DROPOUT') => $columns['dropouts'] = $i,
                str_contains($label, 'COUNTY') => $columns['county'] = $i,
                str_contains($label, 'SCHOOL') && ! str_contains($label, 'DROPOUT') => $columns['school'] = $i,
                str_contains($label, 'LEA') && ! str_contains($label, 'TYPE') => $columns['name'] = $i,
                default => null,
            };
        }

        return $columns;
    }

    /**
     * @param  list<string>  $normalized
     * @param  callable(string): bool  $matches
     */
    private function findColumn(array $normalized, callable $matches): ?int
    {
        foreach ($normalized as $i => $label) {
            if ($label !== '' && $matches($label)) {
                return $i;
            }
        }

        return null;
    }

    private function normalize(mixed $cell): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $cell));
    }

    private function cell(array $row, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        $value = trim((string) ($row[$index] ?? ''));

        return $value === '' ? null : $value;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
