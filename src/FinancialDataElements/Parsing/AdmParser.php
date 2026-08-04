<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses the main "... ADM-WADM" sheet of an Average Daily Membership
 * workbook into a RowTable with one row per LEA.
 *
 * PDE's core metric columns (ADM, WADM, Adjusted ADM, Adjustment Factor) are
 * present every year; three more (Nonresident ADM, total ADM, and Special
 * Education ADM - all "for PDE-363", a specific PDE reporting form) were
 * added starting with the 2024-25 workbook and come through as null on older
 * years. Everything else - the per-category ADM/WADM breakdown (Pre-K/
 * Kindergarten AM-PM-full-day splits, Elementary, Secondary) - is kept as a
 * raw label=>value `breakdown` map exactly as PDE publishes it, rather than
 * collapsed into Enrollment's PK/K/1-12 scale: "Elementary"/"Secondary" here
 * are ADM-specific buckets that don't line up with per-grade headcounts.
 *
 * Newer workbooks (2024-25+) add "CS ADM by SD" and "CS ADM by CS" sheets
 * (charter school ADM cross-referenced by sending district and by charter
 * school); not modeled here, same as Personnel's individual-staff and
 * support-staff files.
 */
class AdmParser
{
    private const SHEET_NAME_FRAGMENT = 'ADM-WADM';

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function parse(string $path): RowTable
    {
        $sheet = $this->locateSheet($path);

        $districts = [];
        $rows = [];
        $meta = null;
        $metrics = null;
        $breakdownLabels = null;

        foreach ($this->reader->rows($path, $sheet) as $row) {
            if ($meta === null) {
                $normalized = array_map($this->normalize(...), $row);

                if (! in_array('AUN', $normalized, true)) {
                    continue;
                }

                [$meta, $metrics, $breakdownLabels] = $this->classifyColumns($normalized, $row);

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

            $breakdown = [];

            foreach ($breakdownLabels as $index => $label) {
                $value = $this->numeric($row[$index] ?? null);

                if ($value !== null) {
                    $breakdown[$label] = $value;
                }
            }

            $rows[$aun][] = [
                'adm' => $this->numeric($row[$metrics['adm']] ?? null),
                'wadm' => $this->numeric($row[$metrics['wadm']] ?? null),
                'adjusted_adm' => $this->numeric($row[$metrics['adjusted_adm']] ?? null),
                'nonresident_adm' => $this->numeric($row[$metrics['nonresident_adm']] ?? null),
                'total_adm_pde363' => $this->numeric($row[$metrics['total_adm_pde363']] ?? null),
                'special_education_adm' => $this->numeric($row[$metrics['special_education_adm']] ?? null),
                'adjustment_factor' => $this->numeric($row[$metrics['adjustment_factor']] ?? null),
                'breakdown' => $breakdown,
            ];
        }

        if ($meta === null) {
            throw new PDEClientException("No sheet in [{$path}] has a header row with an AUN column.");
        }

        return new RowTable($districts, $rows);
    }

    private function locateSheet(string $path): string
    {
        foreach ($this->reader->sheetNames($path) as $name) {
            if (str_contains($name, self::SHEET_NAME_FRAGMENT)) {
                return $name;
            }
        }

        throw new PDEClientException(
            'No sheet in ['.$path.'] has a name containing ['.self::SHEET_NAME_FRAGMENT.'].'
        );
    }

    /**
     * @param  list<string>  $normalized
     * @param  list<mixed>  $rawRow
     * @return array{0: array<string, ?int>, 1: array<string, ?int>, 2: array<int, string>}
     */
    private function classifyColumns(array $normalized, array $rawRow): array
    {
        $meta = ['aun' => null, 'name' => null, 'county' => null];
        $metrics = ['adm' => null, 'wadm' => null, 'adjusted_adm' => null, 'nonresident_adm' => null,
            'total_adm_pde363' => null, 'special_education_adm' => null, 'adjustment_factor' => null];
        $breakdownLabels = [];

        foreach ($normalized as $i => $label) {
            match (true) {
                $label === 'AUN' => $meta['aun'] = $i,
                $label === 'SCHOOLDISTRICT' => $meta['name'] = $i,
                $label === 'COUNTY' => $meta['county'] = $i,
                str_contains($label, 'WEIGHTEDAVERAGEDAILYMEMBERSHIP') => $metrics['wadm'] = $i,
                str_contains($label, 'AVERAGEDAILYMEMBERSHIP') => $metrics['adm'] = $i,
                str_contains($label, 'ADJUSTEDADM') => $metrics['adjusted_adm'] = $i,
                str_contains($label, 'NONRESIDENTADM') => $metrics['nonresident_adm'] = $i,
                str_contains($label, 'TOTALADM') => $metrics['total_adm_pde363'] = $i,
                str_contains($label, 'SPECIALEDUCATIONADM') => $metrics['special_education_adm'] = $i,
                str_contains($label, 'ADJUSTMENTFACTOR') => $metrics['adjustment_factor'] = $i,
                $label === '' => null,
                default => $breakdownLabels[$i] = trim((string) preg_replace('/\s+/', ' ', (string) $rawRow[$i])),
            };
        }

        if ($meta['aun'] === null || $metrics['adm'] === null) {
            throw new PDEClientException('ADM header row is missing the AUN or ADM column.');
        }

        return [$meta, $metrics, $breakdownLabels];
    }

    private function normalize(mixed $cell): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $cell));
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
