<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses PDE's single, in-place-updated Act 1 "Adjusted Index History"
 * workbook - one row per district, one plain column per school year
 * (2015-16 onward), each cell already the adjusted index as a fraction (e.g.
 * 0.041 for 4.1%). Simpler than EconomicallyDisadvantagedParser's repeating
 * 3-column-per-year groups: there's exactly one value per district per year
 * here, so no super-header forward-fill is needed - a year's column is
 * identified directly by its own header label.
 */
class ActOneIndexParser
{
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

        $column = $yearColumns[$year->long()] ?? null;

        if ($column === null) {
            throw new PDEClientException(
                "Act 1 Index sheet [{$sheet}] in [{$path}] has no column for [{$year->long()}]."
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
                'county' => $meta['county'] !== null ? (trim((string) ($row[$meta['county']] ?? '')) ?: null) : null,
            ];

            $rows[$aun][] = [
                'index' => $this->numeric($row[$column] ?? null),
            ];
        }

        if ($districts === []) {
            throw new PDEClientException("Act 1 Index sheet [{$sheet}] in [{$path}] produced no district rows.");
        }

        return new RowTable($districts, $rows);
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, int>, 3: array<string, ?int>}
     */
    private function locateHeader(string $path): array
    {
        foreach ($this->reader->sheetNames($path) as $sheet) {
            foreach ($this->reader->rows($path, $sheet) as $index => $row) {
                if ($index > 10) {
                    break;
                }

                $normalized = array_map(fn ($cell) => strtoupper(trim((string) $cell)), $row);
                $aunIndex = array_search('AUN', $normalized, true);

                if ($aunIndex === false) {
                    continue;
                }

                $meta = [
                    'aun' => $aunIndex,
                    'name' => $this->findColumn($normalized, 'SCHOOL DISTRICT'),
                    'county' => $this->findColumn($normalized, 'COUNTY'),
                ];

                $yearColumns = $this->classifyYearColumns($row);

                if ($yearColumns === []) {
                    throw new PDEClientException(
                        "Act 1 Index sheet [{$sheet}] in [{$path}] has an AUN header row but no year columns."
                    );
                }

                return [$sheet, $index, $yearColumns, $meta];
            }
        }

        throw new PDEClientException("No sheet in [{$path}] has a header row with an AUN column.");
    }

    /**
     * @param  list<mixed>  $row
     * @return array<string, int> long-year => column index
     */
    private function classifyYearColumns(array $row): array
    {
        $years = [];

        foreach ($row as $i => $cell) {
            $label = trim((string) $cell);

            if (! preg_match('/^\d{4}-\d{2,4}$/', $label)) {
                continue;
            }

            $years[FiscalYear::parse($label)->long()] = $i;
        }

        return $years;
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
