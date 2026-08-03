<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Personnel\Finders\PersonnelFileFinder;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;

/**
 * Resolves professional staff summary workbooks (one per school year) into
 * local file paths.
 */
class PersonnelFileLocator
{
    public function __construct(
        private readonly PersonnelFileFinder $finder,
        private readonly FileDownloader $downloader,
        private readonly LocalWorkbookStore $store,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableYears(): array
    {
        $years = $this->summaryFiles()
            ->map(fn (RemoteFile $file) => $file->period)
            ->filter()
            ->unique()
            ->map(fn (string $period) => FiscalYear::parse($period))
            ->all();

        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return array_values($years);
    }

    public function summaryWorkbookPath(FiscalYear $year): string
    {
        $file = $this->summaryFiles()
            ->first(fn (RemoteFile $file) => $file->period === $year->long());

        if ($file === null) {
            throw DataSetNotFoundException::noneMatched(
                "a professional staff summary workbook for [{$year->long()}]"
            );
        }

        $directory = config('pde-client.personnel.download_directory', 'pde-client/personnel');

        return $this->store->ensureLocal($file, $directory);
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    private function summaryFiles(): Collection
    {
        return (new PersonnelFiles($this->finder, $this->downloader))
            ->category('staff_summary')
            ->get();
    }
}
