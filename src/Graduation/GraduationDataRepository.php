<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\Parsing\CohortRatesParser;
use WiserWebSolutions\PDEClient\Graduation\Parsing\DropoutsParser;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Hands the graduation query layer parsed RowTables per (span, year) for
 * cohort rates and per year for dropout summaries, hiding the
 * download-if-missing step and the parsed-table cache.
 */
class GraduationDataRepository
{
    use RemembersParsedRowTables;

    public function __construct(
        private readonly GraduationFileLocator $locator,
        private readonly CohortRatesParser $cohortParser,
        private readonly DropoutsParser $dropoutsParser,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableCohortYears(int $span): array
    {
        return $this->locator->availableCohortYears($span);
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableDropoutYears(): array
    {
        return $this->locator->availableDropoutYears();
    }

    public function cohortTable(int $span, FiscalYear $year): RowTable
    {
        return $this->rememberRowTable(
            "graduation:cohort{$span}:{$year->long()}",
            fn () => $this->cohortParser->parse($this->locator->cohortWorkbookPath($span, $year)),
        );
    }

    public function dropoutTable(FiscalYear $year): RowTable
    {
        return $this->rememberRowTable(
            "graduation:dropouts:{$year->long()}",
            fn () => $this->dropoutsParser->parse($this->locator->dropoutWorkbookPath($year)),
        );
    }
}
