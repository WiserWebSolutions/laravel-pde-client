<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Parses one fiscal-year tab of an AFR "detailed data" workbook (Local/State/
 * Federal/Other Revenue, Expenditure Detail) into an actuals YearTable.
 *
 * These workbooks have one sheet per year ("2024-25" ... "2015-16"), one row
 * per LEA, and pack the account code into the tail of each column header
 * (e.g. "Current Real Estate Taxes\n6111", "Regular\nPrograms 1110").
 * Columns with no trailing code ("Total Expenditures", per-ADM stats, ...)
 * are skipped - the coded rollups (6000, 1000, 1100, ...) already cover the
 * aggregate views.
 *
 * The meta columns are located by header name, not position, because the
 * files disagree with each other: some lead with a category column named
 * "Cat", "Ctg", or "Ctgy", and some start straight at "AUN".
 */
class AfrDetailWorkbookParser
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

    public function parseYear(string $path, FiscalYear $year): YearTable
    {
        $rows = $this->reader->rows($path, $year->short());

        $header = null;
        $aunIndex = $nameIndex = $countyIndex = null;
        $codeByColumn = [];
        $accountNames = [];
        $districts = [];
        $amounts = [];

        foreach ($rows as $row) {
            if ($header === null) {
                $header = $row;

                foreach ($header as $index => $label) {
                    $label = preg_replace('/\s+/', ' ', trim((string) $label));

                    if ($label === 'AUN') {
                        $aunIndex = $index;
                    } elseif ($label === 'LEA Name' || $label === 'School District') {
                        $nameIndex = $index;
                    } elseif ($label === 'County') {
                        $countyIndex = $index;
                    } elseif (preg_match('/^(.*?)\s*(\d{4})\s*$/', $label, $matches)) {
                        $codeByColumn[$index] = $matches[2];
                        $accountNames[$matches[2]] = trim($matches[1]) ?: null;
                    }
                }

                if ($aunIndex === null || $codeByColumn === []) {
                    throw new PDEClientException(
                        "AFR sheet [{$year->short()}] in [{$path}] does not have the expected AUN/account-code columns."
                    );
                }

                continue;
            }

            $aun = trim((string) ($row[$aunIndex] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] = [
                'name' => $nameIndex !== null ? (trim((string) ($row[$nameIndex] ?? '')) ?: null) : null,
                'county' => $countyIndex !== null ? (trim((string) ($row[$countyIndex] ?? '')) ?: null) : null,
            ];

            foreach ($codeByColumn as $index => $code) {
                $value = $row[$index] ?? null;

                if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
                    continue;
                }

                $amounts[$aun][$code] = round((float) $value, 2);
            }
        }

        return new YearTable($districts, $amounts, $accountNames);
    }
}
