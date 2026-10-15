<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\Enrollment\Parsing\EconomicallyDisadvantagedParser;
use WiserWebSolutions\PDEClient\Enrollment\Parsing\EnglishLearnersParser;
use WiserWebSolutions\PDEClient\Enrollment\Parsing\ProjectionsParser;
use WiserWebSolutions\PDEClient\Enrollment\Parsing\PublicEnrollmentParser;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RemembersWorkbookYears;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Support\WorkbookFingerprint;

/**
 * Hands the enrollment query layer parsed YearTables per (dataset, year),
 * hiding which workbook the numbers came from, the download-if-missing
 * step, and the parsed-table cache - mirrors FinancialDataRepository.
 */
class EnrollmentDataRepository
{
    use RemembersParsedRowTables;
    use RemembersWorkbookYears;

    /** @var array<string, YearTable> per-request memo on top of the Laravel cache */
    private array $memo = [];

    public function __construct(
        private readonly EnrollmentFileLocator $locator,
        private readonly PublicEnrollmentParser $publicParser,
        private readonly EnglishLearnersParser $elParser,
        private readonly ProjectionsParser $projectionsParser,
        private readonly EconomicallyDisadvantagedParser $economicallyDisadvantagedParser,
        private readonly Cache $cache,
    ) {}

    /**
     * @return list<FiscalYear> newest first
     */
    public function availablePublicYears(): array
    {
        return $this->locator->availablePublicYears();
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableElYears(): array
    {
        return $this->locator->availableElYears();
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableProjectionYears(): array
    {
        $path = $this->locator->projectionsWorkbookPath();

        return $this->rememberYears($path, fn () => $this->projectionsParser->availableYears($path), 'projectionsParser');
    }

    public function publicTable(FiscalYear $year): YearTable
    {
        $path = $this->locator->publicWorkbookPath($year);

        return $this->remember("enrollment:public:{$year->long()}", fn () => $this->publicParser->parse($path), $path);
    }

    public function elTable(FiscalYear $year): YearTable
    {
        $path = $this->locator->elWorkbookPath($year);

        return $this->remember("enrollment:el:{$year->long()}", fn () => $this->elParser->parse($path), $path);
    }

    public function projectionTable(FiscalYear $year): YearTable
    {
        $path = $this->projectionsPathWithYear($year);

        return $this->remember("enrollment:projections:{$year->long()}", fn () => $this->projectionsParser->parseYear($path, $year), $path);
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableEconomicallyDisadvantagedYears(): array
    {
        $path = $this->locator->economicallyDisadvantagedWorkbookPath();

        return $this->rememberYears($path, fn () => $this->economicallyDisadvantagedParser->availableYears($path), 'economicallyDisadvantagedParser');
    }

    public function economicallyDisadvantagedTable(FiscalYear $year): RowTable
    {
        $path = $this->economicallyDisadvantagedPathWithYear($year);

        return $this->rememberRowTable("enrollment:economically-disadvantaged:{$year->long()}", fn () => $this->economicallyDisadvantagedParser->parseYear($path, $year), $path);
    }

    /**
     * The projections workbook is updated in place (new years appended as
     * rows, not new files), so a locally cached copy can predate the
     * requested year - re-download once and retry, same trick
     * FinancialDataRepository uses for the AFR detail workbooks.
     */
    private function projectionsPathWithYear(FiscalYear $year): string
    {
        $path = $this->locator->projectionsWorkbookPath();

        if ($this->workbookHasYear($path, $year, $this->projectionsParser->availableYears(...))) {
            return $path;
        }

        $path = $this->locator->projectionsWorkbookPath(refresh: true);

        if ($this->workbookHasYear($path, $year, $this->projectionsParser->availableYears(...))) {
            return $path;
        }

        throw DataSetNotFoundException::noneMatched(
            "fiscal year [{$year->short()}] in the enrollment projections workbook (available: ".
            implode(', ', array_map(fn (FiscalYear $y) => $y->short(), $this->projectionsParser->availableYears($path))).')'
        );
    }

    /**
     * The economically disadvantaged workbook is likewise updated in place
     * (a new year's column group appended, not a new file).
     */
    private function economicallyDisadvantagedPathWithYear(FiscalYear $year): string
    {
        $path = $this->locator->economicallyDisadvantagedWorkbookPath();

        if ($this->workbookHasYear($path, $year, $this->economicallyDisadvantagedParser->availableYears(...))) {
            return $path;
        }

        $path = $this->locator->economicallyDisadvantagedWorkbookPath(refresh: true);

        if ($this->workbookHasYear($path, $year, $this->economicallyDisadvantagedParser->availableYears(...))) {
            return $path;
        }

        throw DataSetNotFoundException::noneMatched(
            "fiscal year [{$year->short()}] in the economically disadvantaged workbook (available: ".
            implode(', ', array_map(fn (FiscalYear $y) => $y->short(), $this->economicallyDisadvantagedParser->availableYears($path))).')'
        );
    }

    /**
     * @param  callable(string): list<FiscalYear>  $availableYears
     */
    private function workbookHasYear(string $path, FiscalYear $year, callable $availableYears): bool
    {
        foreach ($this->rememberYears($path, fn () => $availableYears($path), (new \ReflectionFunction($availableYears))->getName()) as $available) {
            if ($available->equals($year)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(): YearTable  $produce
     */
    private function remember(string $key, callable $produce, string|array $sources = []): YearTable
    {
        $key .= ':'.WorkbookFingerprint::forPaths($sources);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $ttl = (int) config('pde-client.parsed_cache_ttl', 86400);

        if ($ttl <= 0) {
            return $this->memo[$key] = $produce();
        }

        // Stored as plain arrays: object graphs don't survive every cache
        // driver's serialization (see AbstractHtmlFinder for the details).
        $cached = $this->cache->remember(
            'pde-client:parsed:v'.WorkbookFingerprint::PARSER_VERSION.':'.$key,
            $ttl,
            fn () => $produce()->toArray(),
        );

        return $this->memo[$key] = YearTable::fromArray($cached);
    }
}
