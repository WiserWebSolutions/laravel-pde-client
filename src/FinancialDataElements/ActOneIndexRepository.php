<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\ActOneIndexParser;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RemembersWorkbookYears;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Hands the Act 1 Index query layer parsed RowTables per year, hiding the
 * download-if-missing step and the parsed-table cache - mirrors
 * FinancialDataElementsRepository.
 */
class ActOneIndexRepository
{
    use RemembersParsedRowTables;
    use RemembersWorkbookYears;

    public function __construct(
        private readonly ActOneIndexFileLocator $locator,
        private readonly ActOneIndexParser $parser,
        private readonly Cache $cache,
    ) {}

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableYears(): array
    {
        $path = $this->locator->adjustedIndexHistoryWorkbookPath();

        return $this->rememberYears($path, fn () => $this->parser->availableYears($path), 'parser');
    }

    public function table(FiscalYear $year): RowTable
    {
        $path = $this->pathWithYear($year);

        return $this->rememberRowTable("act-one-index:{$year->long()}", fn () => $this->parser->parseYear($path, $year), $path);
    }

    /**
     * The adjusted index history workbook is updated in place (a new year's
     * column appended, not a new file), so a locally cached copy can predate
     * the requested year - re-download once and retry, the same trick
     * EnrollmentDataRepository uses for its own in-place-updated workbooks.
     */
    private function pathWithYear(FiscalYear $year): string
    {
        $path = $this->locator->adjustedIndexHistoryWorkbookPath();

        if ($this->workbookHasYear($path, $year)) {
            return $path;
        }

        $path = $this->locator->adjustedIndexHistoryWorkbookPath(refresh: true);

        if ($this->workbookHasYear($path, $year)) {
            return $path;
        }

        throw DataSetNotFoundException::noneMatched(
            "fiscal year [{$year->short()}] in the Act 1 adjusted index workbook (available: ".
            implode(', ', array_map(fn (FiscalYear $y) => $y->short(), $this->rememberYears($path, fn () => $this->parser->availableYears($path), 'act-one-index'))).')'
        );
    }

    private function workbookHasYear(string $path, FiscalYear $year): bool
    {
        foreach ($this->rememberYears($path, fn () => $this->parser->availableYears($path), 'parser') as $available) {
            if ($available->equals($year)) {
                return true;
            }
        }

        return false;
    }
}
