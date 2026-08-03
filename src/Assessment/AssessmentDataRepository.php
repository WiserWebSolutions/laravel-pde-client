<?php

namespace WiserWebSolutions\PDEClient\Assessment;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\Assessment\Parsing\AssessmentWorkbookParser;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Hands the assessment query layer parsed RowTables per (exam, year),
 * hiding the download-if-missing step and the parsed-table cache.
 */
class AssessmentDataRepository
{
    use RemembersParsedRowTables;

    public function __construct(
        private readonly AssessmentFileLocator $locator,
        private readonly AssessmentWorkbookParser $parser,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @param  'pssa'|'keystone'  $exam
     * @return list<FiscalYear> newest first
     */
    public function availableYears(string $exam): array
    {
        return $this->locator->availableYears($exam);
    }

    /**
     * @param  'pssa'|'keystone'  $exam
     */
    public function table(string $exam, FiscalYear $year): RowTable
    {
        return $this->rememberRowTable(
            "assessment:{$exam}:{$year->long()}",
            fn () => $this->parser->parse($this->locator->workbookPath($exam, $year)),
        );
    }
}
