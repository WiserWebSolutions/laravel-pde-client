<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\Finders\GraduationFileFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;

/**
 * Resolves cohort-graduation-rate and dropout workbooks into local file
 * paths. Cohort files are keyed by (school year, cohort span): each year
 * publishes separate 4-, 5-, and 6-year cohort workbooks whose span appears
 * only in the filename ("...4-year cohort graduation rates.xlsx"), so the
 * span is matched against the filename here rather than being carried on
 * RemoteFile.
 */
class GraduationFileLocator
{
    public const COHORT_SPANS = [4, 5, 6];

    public function __construct(
        private readonly GraduationFileFinder $finder,
        private readonly FileDownloader $downloader,
        private readonly LocalWorkbookStore $store,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableCohortYears(int $span): array
    {
        return $this->newestFirst(
            $this->cohortFiles($span)->map(fn (RemoteFile $file) => $file->period)->filter()->all()
        );
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableDropoutYears(): array
    {
        return $this->newestFirst(
            $this->files('dropouts')->map(fn (RemoteFile $file) => $file->period)->filter()->all()
        );
    }

    public function cohortWorkbookPath(int $span, FiscalYear $year): string
    {
        $file = $this->cohortFiles($span)
            ->first(fn (RemoteFile $file) => $file->period === $year->long());

        if ($file === null) {
            throw DataSetNotFoundException::noneMatched(
                "a {$span}-year cohort graduation workbook for [{$year->long()}]"
            );
        }

        return $this->store->ensureLocal($file, $this->directory());
    }

    public function dropoutWorkbookPath(FiscalYear $year): string
    {
        $file = $this->files('dropouts')
            ->first(fn (RemoteFile $file) => $file->period === $year->long());

        if ($file === null) {
            throw DataSetNotFoundException::noneMatched(
                "a dropout summary workbook for [{$year->long()}]"
            );
        }

        return $this->store->ensureLocal($file, $this->directory());
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    private function cohortFiles(int $span): Collection
    {
        if (! in_array($span, self::COHORT_SPANS, true)) {
            throw new PDEClientException("Cohort span must be 4, 5, or 6; got [{$span}].");
        }

        return $this->files('cohort')
            ->filter(fn (RemoteFile $file) => str_contains(strtolower($file->filename()), "{$span}-year"))
            ->values();
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    private function files(string $category): Collection
    {
        return (new GraduationFiles($this->finder, $this->downloader))->category($category)->get();
    }

    /**
     * @param  list<string>  $periods
     * @return list<FiscalYear>
     */
    private function newestFirst(array $periods): array
    {
        $years = array_map(fn (string $period) => FiscalYear::parse($period), array_values(array_unique($periods)));

        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return $years;
    }

    private function directory(): string
    {
        return config('pde-client.graduation.download_directory', 'pde-client/graduation');
    }
}
