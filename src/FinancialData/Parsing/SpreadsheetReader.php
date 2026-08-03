<?php

namespace WiserWebSolutions\PDEClient\FinancialData\Parsing;

use Generator;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;

/**
 * Streaming spreadsheet reader for the PDE workbooks, dispatching by file
 * extension: openspout for `.xlsx` (the vast majority of files - true
 * streaming, since these run from a few hundred KB to tens of MB with
 * hundreds of columns) and PhpSpreadsheet for `.xls` (PDE's older,
 * pre-2012-ish files - openspout doesn't support the legacy BIFF binary
 * format at all). PhpSpreadsheet loads the whole workbook into memory rather
 * than truly streaming it, but every `.xls` file PDE publishes here predates
 * the current wide-format templates and is correspondingly small, so that
 * tradeoff is fine in exchange for not silently excluding a decade of data.
 *
 * Both branches present the same generator-based interface, so every Parser
 * in this package is unaware of which library actually read a given file.
 */
class SpreadsheetReader
{
    /**
     * Loaded .xls workbooks, keyed by path - PhpSpreadsheet's Xls reader
     * loads the entire workbook per call, and several Parsers scan every
     * sheet name before knowing which one they want (see e.g.
     * PublicEnrollmentParser::locateHeader()); without this cache, each of
     * those lookups would re-parse the whole file from scratch and can
     * exhaust memory on a workbook with several sheets.
     *
     * @var array<string, Spreadsheet>
     */
    private array $xlsCache = [];

    /**
     * @return list<string>
     */
    public function sheetNames(string $path): array
    {
        return $this->isLegacyXls($path)
            ? $this->loadXls($path)->getSheetNames()
            : $this->xlsxSheetNames($path);
    }

    /**
     * Streams one sheet's rows as plain value arrays.
     *
     * @return Generator<int, list<mixed>>
     */
    public function rows(string $path, string $sheetName): Generator
    {
        yield from $this->isLegacyXls($path)
            ? $this->xlsRows($path, $sheetName)
            : $this->xlsxRows($path, $sheetName);
    }

    private function isLegacyXls(string $path): bool
    {
        if (! is_file($path)) {
            throw new PDEClientException("Spreadsheet not found at [{$path}].");
        }

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xls';
    }

    /**
     * @return list<string>
     */
    private function xlsxSheetNames(string $path): array
    {
        $reader = $this->openXlsx($path);

        try {
            $names = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }

            return $names;
        } finally {
            $reader->close();
        }
    }

    /**
     * @return Generator<int, list<mixed>>
     */
    private function xlsxRows(string $path, string $sheetName): Generator
    {
        $reader = $this->openXlsx($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== $sheetName) {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }

                return;
            }

            throw new PDEClientException(
                "Worksheet [{$sheetName}] not found in [{$path}]; available: ".
                implode(', ', $this->xlsxSheetNames($path))
            );
        } finally {
            $reader->close();
        }
    }

    private function openXlsx(string $path): XlsxReader
    {
        $reader = new XlsxReader();
        $reader->open($path);

        return $reader;
    }

    /**
     * @return Generator<int, list<mixed>>
     */
    private function xlsRows(string $path, string $sheetName): Generator
    {
        $spreadsheet = $this->loadXls($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            throw new PDEClientException(
                "Worksheet [{$sheetName}] not found in [{$path}]; available: ".
                implode(', ', $spreadsheet->getSheetNames())
            );
        }

        $columnCount = $sheet->getHighestDataColumn();

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            $cellIterator = $row->getCellIterator('A', $columnCount);
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            yield $cells;
        }
    }

    private function loadXls(string $path): Spreadsheet
    {
        return $this->xlsCache[$path] ??= (new XlsReader())->load($path);
    }
}
