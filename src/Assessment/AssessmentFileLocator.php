<?php

namespace WiserWebSolutions\PDEClient\Assessment;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Assessment\Finders\AssessmentFileFinder;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;

/**
 * Resolves the district-level PSSA/Keystone workbooks the assessment query
 * layer needs into local file paths. Only district-level files matter here -
 * the whole query API is district-keyed - so the state- and school-level
 * files each year also publishes are filtered out by filename.
 */
class AssessmentFileLocator
{
    public function __construct(
        private readonly AssessmentFileFinder $finder,
        private readonly FileDownloader $downloader,
        private readonly LocalWorkbookStore $store,
    ) {
    }

    /**
     * @param  'pssa'|'keystone'  $exam
     * @return list<FiscalYear> newest first
     */
    public function availableYears(string $exam): array
    {
        $years = $this->districtFiles($exam)
            ->map(fn (RemoteFile $file) => $file->period)
            ->filter()
            ->unique()
            ->map(fn (string $period) => FiscalYear::parse($period))
            ->all();

        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return array_values($years);
    }

    /**
     * @param  'pssa'|'keystone'  $exam
     */
    public function workbookPath(string $exam, FiscalYear $year): string
    {
        $file = $this->districtFiles($exam)
            ->first(fn (RemoteFile $file) => $file->period === $year->long());

        if ($file === null) {
            throw DataSetNotFoundException::noneMatched(
                "a district-level {$exam} workbook for [{$year->long()}]"
            );
        }

        $directory = config('pde-client.assessment.download_directory', 'pde-client/assessment');

        return $this->store->ensureLocal($file, $directory);
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    private function districtFiles(string $exam): Collection
    {
        return (new AssessmentFiles($this->finder, $this->downloader))
            ->category($exam)
            ->get()
            ->filter(fn (RemoteFile $file) => str_contains(strtolower($file->filename()), 'district'))
            ->values();
    }
}
