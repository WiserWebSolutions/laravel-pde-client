<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Personnel\Parsing\StaffSummaryParser;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Hands the personnel query layer parsed RowTables per year, hiding the
 * download-if-missing step and the parsed-table cache.
 */
class PersonnelDataRepository
{
    use RemembersParsedRowTables;

    public function __construct(
        private readonly PersonnelFileLocator $locator,
        private readonly StaffSummaryParser $parser,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableYears(): array
    {
        return $this->locator->availableYears();
    }

    public function table(FiscalYear $year): RowTable
    {
        return $this->rememberRowTable(
            "personnel:summary:{$year->long()}",
            fn () => $this->parser->parse($this->locator->summaryWorkbookPath($year)),
        );
    }
}
