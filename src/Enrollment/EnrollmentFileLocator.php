<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Enrollment\Finders\EnrollmentFileFinder;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;

/**
 * Resolves the workbooks the enrollment query layer needs into local file
 * paths, mirroring FinancialData\DataFileLocator: public enrollment and
 * English learner counts publish one workbook per school year, while
 * enrollment projections is a single workbook PDE updates in place.
 *
 * Builds a fresh EnrollmentFiles per lookup rather than reusing one instance
 * across calls - EnrollmentFiles/DataSource's category()/matching() mutate
 * and return $this, so a shared instance would leak one lookup's filters
 * into the next (e.g. availablePublicYears() picking up a leftover
 * ->matching() from a prior publicWorkbookPath() call).
 */
class EnrollmentFileLocator
{
    private const PROJECTIONS_LABEL = 'School District Enrollment Projections';

    private const LOW_INCOME_LABEL = 'Low Income Enrollments Public School';

    public function __construct(
        private readonly EnrollmentFileFinder $finder,
        private readonly FileDownloader $downloader,
        private readonly LocalWorkbookStore $store,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availablePublicYears(): array
    {
        return $this->availableYears('public');
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableElYears(): array
    {
        return $this->availableYears('english_learners');
    }

    public function publicWorkbookPath(FiscalYear $year): string
    {
        return $this->store->ensureLocal($this->fileForYear('public', $year), $this->directory());
    }

    public function elWorkbookPath(FiscalYear $year): string
    {
        return $this->store->ensureLocal($this->fileForYear('english_learners', $year), $this->directory());
    }

    /**
     * Matches on the Finder's already-validated `period` exactly, rather
     * than a loose label substring search: PDE's page also lists range/
     * consolidated files (e.g. "...2015-2016 through 2024-2025
     * Consolidated") whose label contains a real year as a substring even
     * though the Finder correctly leaves their period null - ->matching()
     * alone would match those alongside the real single-year file and make
     * sole() throw "multiple matched" for that year.
     */
    private function fileForYear(string $category, FiscalYear $year): RemoteFile
    {
        $file = $this->files()->category($category)->get()
            ->first(fn (RemoteFile $file) => $file->period === $year->long());

        if ($file === null) {
            throw DataSetNotFoundException::noneMatched(
                "category [{$category}] and year [{$year->long()}]"
            );
        }

        return $file;
    }

    /**
     * @param  bool  $refresh  force a re-download (used when a cached copy predates the requested year's tab)
     */
    public function projectionsWorkbookPath(bool $refresh = false): string
    {
        $file = $this->files()->category('projections')->matching(self::PROJECTIONS_LABEL)->sole();

        return $this->store->ensureLocal($file, $this->directory(), $refresh);
    }

    /**
     * The 'low_income' category also holds per-year "Percent Low Income"
     * files (public and private/nonpublic) whose label doesn't contain this
     * one's - matching() disambiguates the single ten-year consolidated
     * count/enrollment/percent workbook from those.
     *
     * @param  bool  $refresh  force a re-download (used when a cached copy predates the requested year's column group)
     */
    public function lowIncomeWorkbookPath(bool $refresh = false): string
    {
        $file = $this->files()->category('low_income')->matching(self::LOW_INCOME_LABEL)->sole();

        return $this->store->ensureLocal($file, $this->directory(), $refresh);
    }

    /**
     * @return list<FiscalYear> newest first
     */
    private function availableYears(string $category): array
    {
        $years = $this->files()->category($category)->get()
            ->map(fn (RemoteFile $file) => $file->period)
            ->filter()
            ->map(fn (string $period) => FiscalYear::parse($period))
            ->all();

        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return array_values($years);
    }

    private function files(): EnrollmentFiles
    {
        return new EnrollmentFiles($this->finder, $this->downloader);
    }

    private function directory(): string
    {
        return config('pde-client.enrollment.download_directory', 'pde-client/enrollment');
    }
}
