<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds real, on-disk .xlsx/.xls fixture workbooks for parser tests -
 * SpreadsheetReader (src/FinancialData/Parsing/SpreadsheetReader.php) reads
 * .xlsx via openspout and .xls via PhpSpreadsheet, so a genuine file on disk
 * exercises the real reader instead of a hand-rolled double standing in for
 * it. Every parser in this package is handed a bare file path, so these
 * fixtures work directly with any Parser's parseYear()/parse() methods, not
 * just SpreadsheetReader itself.
 *
 * Fixture files land in the OS temp directory (not cleaned up between tests
 * - they're tiny and harmless, and PHPUnit/CI temp dirs get wiped anyway).
 */
trait BuildsFixtureWorkbook
{
    /**
     * @param  array<string, list<list<mixed>>>  $sheets  sheet name => rows, each row a 0-indexed list of cell values
     */
    protected function xlsxFixture(array $sheets): string
    {
        return $this->buildFixture($sheets, fn (Spreadsheet $spreadsheet) => new Xlsx($spreadsheet), 'xlsx');
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets  sheet name => rows, each row a 0-indexed list of cell values
     */
    protected function xlsFixture(array $sheets): string
    {
        return $this->buildFixture($sheets, fn (Spreadsheet $spreadsheet) => new Xls($spreadsheet), 'xls');
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets
     * @param  callable(Spreadsheet): (Xls|Xlsx)  $makeWriter
     */
    private function buildFixture(array $sheets, callable $makeWriter, string $extension): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet = new Worksheet($spreadsheet, $name);
            $spreadsheet->addSheet($sheet);

            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $colIndex => $value) {
                    $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'pde-fixture-').'.'.$extension;
        $makeWriter($spreadsheet)->save($path);

        return $path;
    }
}
