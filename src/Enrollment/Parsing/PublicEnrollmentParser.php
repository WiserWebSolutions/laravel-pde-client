<?php

namespace WiserWebSolutions\PDEClient\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\Support\AmountAccumulator;
use WiserWebSolutions\PDEClient\Support\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Support\WorkbookFingerprint;

/**
 * Parses a public-school enrollment workbook (one file per school year)
 * into a YearTable of raw grade-column counts.
 *
 * Neither the sheet name, the header row's position, nor even AUN's column
 * position is stable across the two decades these files span: the current
 * sheet is "LEA" with AUN in column 1, 2010-11 (the last .xls year) uses
 * "School" with AUN in column 1 too, but 2007-08 through 2009-10 publish a
 * per-SCHOOL "LEA - Report View"/"School - Report View" sheet ALONGSIDE a
 * flat "LEA - Data File"/"School - Data(f)ile" one. The Report View sheet
 * merge-cells AUN/LEA-name/county down each district's block of school rows
 * (blank on every row but the first) and adds a synthetic "<District>
 * Total" row - unhandled here - so whenever both exist the Data File one
 * (every column populated on every row) is preferred. Every sheet is
 * scanned for the first row containing an "AUN" cell anywhere (the same
 * technique AssessmentWorkbookParser uses for its header row, extended to
 * not assume a fixed column), and amounts are summed rather than assigned
 * per AUN so that both the modern one-row-per-LEA shape and the old
 * one-row-per-school shape produce the same district totals.
 *
 * 2004-05 through 2006-07 have no AUN column at all - LEAs are identified
 * only by name in a nested county/district/school outline, which this
 * package has no name-to-AUN crosswalk for. locateHeader() throws for those;
 * These layouts remain parsing errors because the required district identifier
 * cannot be resolved; missing-data configuration does not change that.
 *
 * Columns are PKA/PKP/PKF/K4A/K4P/K4F/K5A/K5P/K5F/001-012/Total in modern
 * files, or the simpler PreK/K4/K5/1-12 in files through 2010-11; everything
 * but the trailing Total is kept as a raw grade code - Grade::normalize()
 * collapses all of these to PK/K/1-12 in EnrollmentQuery.
 */
class PublicEnrollmentParser
{
    private const NON_GRADE_COLUMNS = ['AUN', 'LEA Name', 'LEA', 'LEA Type', 'County', 'County Name', 'School Number', 'School Name', '', 'Total'];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function parse(string $path): YearTable
    {
        [$sheet, $headerIndex, $meta, $gradeColumns] = $this->locateHeader($path);

        $districts = [];
        $amounts = [];
        $availability = [];

        foreach ($this->reader->rows($path, $sheet) as $i => $row) {
            if ($i <= $headerIndex) {
                continue;
            }

            $aun = trim((string) ($row[$meta['aun']] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] ??= [
                'name' => $meta['name'] !== null ? (trim((string) ($row[$meta['name']] ?? '')) ?: null) : null,
                'county' => $meta['county'] !== null ? (trim((string) ($row[$meta['county']] ?? '')) ?: null) : null,
                'lea_type' => $meta['type'] !== null ? (trim((string) ($row[$meta['type']] ?? '')) ?: null) : null,
            ];

            foreach ($gradeColumns as $index => $code) {
                $value = $row[$index] ?? null;

                AmountAccumulator::add($amounts, $availability, $aun, (string) $code, $value, true, null);
            }
        }

        return new YearTable($districts, $amounts, array_fill_keys(array_values($gradeColumns), null), $availability, WorkbookFingerprint::source($path));
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, ?int>, 3: array<int, string>}
     */
    private function locateHeader(string $path): array
    {
        $candidates = [];

        foreach ($this->reader->sheetNames($path) as $sheet) {
            foreach ($this->reader->rows($path, $sheet) as $index => $row) {
                if ($index > 10) {
                    break;
                }

                $labels = array_map(fn ($cell) => trim((string) $cell), $row);
                $aunIndex = array_search('AUN', $labels, true);

                if ($aunIndex === false) {
                    continue;
                }

                $meta = ['aun' => $aunIndex, 'name' => null, 'type' => null, 'county' => null];
                $gradeColumns = [];

                foreach ($labels as $i => $label) {
                    match (true) {
                        $label === 'LEA Name', $label === 'LEA' => $meta['name'] = $i,
                        $label === 'LEA Type' => $meta['type'] = $i,
                        $label === 'County', $label === 'County Name' => $meta['county'] = $i,
                        in_array($label, self::NON_GRADE_COLUMNS, true) => null,
                        default => $gradeColumns[$i] = $label,
                    };
                }

                if ($gradeColumns === []) {
                    throw new PDEClientException(
                        "Sheet [{$sheet}] in [{$path}] has an AUN header row but no grade columns."
                    );
                }

                $candidates[] = [$sheet, $index, $meta, $gradeColumns];

                break;
            }
        }

        if ($candidates === []) {
            throw new PDEClientException(
                "No sheet in [{$path}] has the required AUN column; name-only reports need a verified AUN crosswalk."
            );
        }

        // 2007-08 through 2009-10 publish a print-formatted "...Report
        // View" sheet ahead of a flat "...Data File"/"Datafile" sheet - the
        // Report View one merge-cells AUN/name/county down each district's
        // block of school rows (blank on every row but the first) and adds
        // a synthetic "<District> Total" row, neither of which this parser
        // handles; the Data File sheet has every column populated on every
        // row, so it's preferred whenever both exist.
        usort($candidates, fn (array $a, array $b) => str_contains(strtolower($a[0]), 'report view')
            <=> str_contains(strtolower($b[0]), 'report view'));

        return $candidates[0];
    }
}
