<?php

namespace WiserWebSolutions\PDEClient\Assessment\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Parses a district-level PSSA or Keystone results workbook into a RowTable.
 * Both exams share one long-format layout - one row per
 * (district, subject, group, grade) with proficiency-band percentages - but
 * disagree on column ORDER (PSSA: Subject, Group, Grade; Keystone: Subject,
 * Grade, Group), and the sheet name/title-block height drift across years,
 * so both the sheet and the header row are located by content ("AUN" +
 * "Subject" cells present), and every column is mapped by name.
 *
 * Percent cells for suppressed populations (fewer than 11 students) are
 * non-numeric and come through as null - a suppressed row is still a row.
 */
class AssessmentWorkbookParser
{
    private const COLUMNS = [
        'aun' => 'AUN',
        'county' => 'County',
        'name' => 'District Name',
        'subject' => 'Subject',
        'group' => 'Group',
        'grade' => 'Grade',
        'scored' => 'Number Scored',
        'advanced' => 'Percent Advanced',
        'proficient' => 'Percent Proficient',
        'basic' => 'Percent Basic',
        'below_basic' => 'Percent Below Basic',
        'proficient_or_above' => 'Percent Proficient and above',
    ];

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    public function parse(string $path): RowTable
    {
        [$sheet, $headerIndex, $indexes] = $this->locateHeader($path);

        $districts = [];
        $rows = [];

        foreach ($this->reader->rows($path, $sheet) as $i => $row) {
            if ($i <= $headerIndex) {
                continue;
            }

            $aun = trim((string) ($row[$indexes['aun']] ?? ''));

            if ($aun === '' || ! preg_match('/^\d{9}$/', $aun)) {
                continue;
            }

            $districts[$aun] ??= [
                'name' => trim((string) ($row[$indexes['name']] ?? '')) ?: null,
                'county' => trim((string) ($row[$indexes['county']] ?? '')) ?: null,
                'lea_type' => null,
            ];

            $rows[$aun][] = [
                'subject' => trim((string) ($row[$indexes['subject']] ?? '')),
                'group' => trim((string) ($row[$indexes['group']] ?? '')),
                'grade' => $this->normalizeGrade(trim((string) ($row[$indexes['grade']] ?? ''))),
                'scored' => $this->numeric($row[$indexes['scored']] ?? null),
                'advanced' => $this->numeric($row[$indexes['advanced']] ?? null),
                'proficient' => $this->numeric($row[$indexes['proficient']] ?? null),
                'basic' => $this->numeric($row[$indexes['basic']] ?? null),
                'below_basic' => $this->numeric($row[$indexes['below_basic']] ?? null),
                'proficient_or_above' => $this->numeric($row[$indexes['proficient_or_above']] ?? null),
            ];
        }

        return new RowTable($districts, $rows);
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, int>}
     */
    private function locateHeader(string $path): array
    {
        foreach ($this->reader->sheetNames($path) as $sheet) {
            foreach ($this->reader->rows($path, $sheet) as $index => $row) {
                // Header rows live in the first few lines under a title block.
                if ($index > 10) {
                    break;
                }

                $labels = array_map(fn ($cell) => trim((string) $cell), $row);

                if (! in_array('AUN', $labels, true) || ! in_array('Subject', $labels, true)) {
                    continue;
                }

                $indexes = [];

                foreach (self::COLUMNS as $key => $label) {
                    $position = array_search($label, $labels, true);

                    if ($position === false) {
                        throw new PDEClientException(
                            "Assessment workbook [{$path}] sheet [{$sheet}] is missing expected column [{$label}]."
                        );
                    }

                    $indexes[$key] = $position;
                }

                return [$sheet, $index, $indexes];
            }
        }

        throw new PDEClientException(
            "No sheet in [{$path}] has the expected AUN/Subject header row."
        );
    }

    /**
     * Older workbooks (through the 2021 administration) label the
     * all-tested-grades aggregate row "District Total"; newer ones say
     * "Total". Normalized so ->grade('Total') works across every year.
     */
    private function normalizeGrade(string $grade): string
    {
        return $grade === 'District Total' ? 'Total' : $grade;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
