<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;

/**
 * Parses a General Fund Budget workbook (one file = one fiscal year, one row
 * per LEA) into budget YearTables.
 *
 * Two sheets carry the account-level data (the sheet names changed when PDE
 * revised the workbook template - older years like 2019-20 use the second
 * name of each pair, the column layout is identical):
 * - "Rev_BegFB" / "Rev_BeginFundBal": columns are bare account codes -
 *   revenue codes (6111..9990) plus beginning-fund-balance codes (0810..0850).
 * - "Exp" / "ExpDetail": columns are "function-object" pairs like
 *   "1100-100". Actual (AFR) expenditure detail is only published at
 *   function level, so budgets are aggregated to the function here too -
 *   both measures then describe the same account code and can be merged
 *   side by side.
 */
class GfbWorkbookParser
{
    private const REVENUE_SHEETS = ['Rev_BegFB', 'Rev_BeginFundBal'];

    private const EXPENDITURE_SHEETS = ['Exp', 'ExpDetail'];

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function revenues(string $path): YearTable
    {
        return $this->parseSheet($path, self::REVENUE_SHEETS, function (string $header): ?string {
            return preg_match('/^\d{4}$/', $header) ? $header : null;
        });
    }

    public function expenditures(string $path): YearTable
    {
        // "1100-100" -> function code "1100"; amounts summed across objects.
        return $this->parseSheet($path, self::EXPENDITURE_SHEETS, function (string $header): ?string {
            return preg_match('/^(\d{4})-\d{3}$/', $header, $matches) ? $matches[1] : null;
        });
    }

    /**
     * @param  list<string>  $candidateSheets  known names for this sheet across workbook template generations
     * @param  callable(string): ?string  $headerToCode  maps a column header to an account code, or null to skip the column
     */
    private function parseSheet(string $path, array $candidateSheets, callable $headerToCode): YearTable
    {
        $available = $this->reader->sheetNames($path);
        $sheetName = null;

        foreach ($candidateSheets as $candidate) {
            if (in_array($candidate, $available, true)) {
                $sheetName = $candidate;
                break;
            }
        }

        if ($sheetName === null) {
            throw new PDEClientException(
                'None of the expected GFB sheets ['.implode(', ', $candidateSheets)."] exist in [{$path}]; ".
                'available: '.implode(', ', $available)
            );
        }

        $rows = $this->reader->rows($path, $sheetName);

        $header = null;
        $aunIndex = $nameIndex = $countyIndex = null;
        $codeByColumn = [];
        $districts = [];
        $amounts = [];

        foreach ($rows as $row) {
            if ($header === null) {
                $header = $row;

                foreach ($header as $index => $label) {
                    $label = trim((string) $label);

                    match ($label) {
                        'AUN' => $aunIndex = $index,
                        'InstName' => $nameIndex = $index,
                        'CountyName' => $countyIndex = $index,
                        default => ($code = $headerToCode($label)) !== null
                            ? $codeByColumn[$index] = $code
                            : null,
                    };
                }

                if ($aunIndex === null || $codeByColumn === []) {
                    throw new PDEClientException(
                        "GFB sheet [{$sheetName}] in [{$path}] does not have the expected AUN/account-code columns."
                    );
                }

                continue;
            }

            $aun = trim((string) ($row[$aunIndex] ?? ''));

            if ($aun === '') {
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

                $amounts[$aun][$code] = round(($amounts[$aun][$code] ?? 0.0) + (float) $value, 2);
            }
        }

        // GFB headers are bare codes with no human label; account names come
        // from the chart of accounts (or the AFR files) instead.
        $accountNames = array_fill_keys(array_values(array_unique($codeByColumn)), null);

        return new YearTable($districts, $amounts, $accountNames);
    }
}
