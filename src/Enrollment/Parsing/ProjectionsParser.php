<?php

namespace WiserWebSolutions\PDEClient\Enrollment\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Parses the "Enrollment Projection Data" sheet of PDE's single, in-place-
 * updated enrollment projections workbook. Unlike the other enrollment
 * files, one sheet holds every year as rows (District/School Year pairs)
 * tagged Datatype = Actual|Projection; only Projection rows are used here -
 * the Actual rows duplicate the public enrollment workbook and would double
 * a merged query's counts if included.
 *
 * Grades are just K/001-012 (no PK, no kindergarten sub-variants), which
 * Grade::normalize() already accounts for.
 */
class ProjectionsParser
{
    private const SHEET = 'Enrollment Projection Data';

    private const DATATYPE_PROJECTION = 'Projection';

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableYears(string $path): array
    {
        $years = [];

        foreach ($this->rows($path) as $row) {
            if ($row['datatype'] !== self::DATATYPE_PROJECTION) {
                continue;
            }

            $years[FiscalYear::parse($row['schoolYear'])->startYear] = true;
        }

        $parsed = array_map(fn (int $startYear) => FiscalYear::parse((string) $startYear), array_keys($years));
        usort($parsed, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return $parsed;
    }

    public function parseYear(string $path, FiscalYear $year): YearTable
    {
        $gradeColumns = null;
        $districts = [];
        $amounts = [];

        foreach ($this->rows($path) as $row) {
            $gradeColumns ??= $row['gradeColumns'];

            if ($row['datatype'] !== self::DATATYPE_PROJECTION) {
                continue;
            }

            if (FiscalYear::parse($row['schoolYear'])->startYear !== $year->startYear) {
                continue;
            }

            $aun = $row['aun'];

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] = [
                'name' => $row['name'],
                'county' => $row['county'],
                'lea_type' => null,
            ];

            foreach ($gradeColumns as $index => $code) {
                $value = $row['raw'][$index] ?? null;

                if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
                    continue;
                }

                $amounts[$aun][$code] = (float) $value;
            }
        }

        return new YearTable($districts, $amounts, array_fill_keys(array_values($gradeColumns ?? []), null));
    }

    /**
     * Parses the sheet once into a generator of normalized row data, shared
     * by availableYears() and parseYear() so the header-location and column
     * logic exists in exactly one place.
     *
     * @return iterable<array{datatype: string, aun: string, schoolYear: string, name: ?string, county: ?string, gradeColumns: array<int, string>, raw: list<mixed>}>
     */
    private function rows(string $path): iterable
    {
        $rows = $this->reader->rows($path, self::SHEET);

        $header = null;
        $datatypeIndex = $aunIndex = $yearIndex = $nameIndex = $countyIndex = null;
        $gradeColumns = [];

        foreach ($rows as $row) {
            if ($header === null) {
                $header = $row;

                foreach ($header as $index => $label) {
                    $label = trim((string) $label);

                    match ($label) {
                        'Datatype' => $datatypeIndex = $index,
                        'AUN' => $aunIndex = $index,
                        'School Year' => $yearIndex = $index,
                        'LEA Name' => $nameIndex = $index,
                        'County' => $countyIndex = $index,
                        default => $label !== '' ? $gradeColumns[$index] = $label : null,
                    };
                }

                if ($datatypeIndex === null || $aunIndex === null || $yearIndex === null || $gradeColumns === []) {
                    throw new PDEClientException(
                        'Enrollment projections sheet ['.self::SHEET."] in [{$path}] does not have the expected columns."
                    );
                }

                continue;
            }

            yield [
                'datatype' => trim((string) ($row[$datatypeIndex] ?? '')),
                'aun' => trim((string) ($row[$aunIndex] ?? '')),
                'schoolYear' => trim((string) ($row[$yearIndex] ?? '')),
                'name' => $nameIndex !== null ? (trim((string) ($row[$nameIndex] ?? '')) ?: null) : null,
                'county' => $countyIndex !== null ? (trim((string) ($row[$countyIndex] ?? '')) ?: null) : null,
                'gradeColumns' => $gradeColumns,
                'raw' => $row,
            ];
        }

        if ($header === null) {
            throw new PDEClientException(
                'Enrollment projections sheet ['.self::SHEET."] in [{$path}] is empty."
            );
        }
    }
}
