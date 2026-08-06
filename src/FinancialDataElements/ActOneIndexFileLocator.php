<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\FinancialDataElements\Finders\ActOneIndexFinder;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;

/**
 * Resolves the Act 1 Index query's workbook into a local file path - a
 * single, in-place-updated "Adjusted Index History" workbook (new years
 * appended as columns, not new files), mirroring EnrollmentFileLocator's
 * economically disadvantaged/projections handling.
 */
class ActOneIndexFileLocator
{
    private const ADJUSTED_INDEX_HISTORY_CATEGORY = 'adjusted_index_history';

    public function __construct(
        private readonly ActOneIndexFinder $finder,
        private readonly FileDownloader $downloader,
        private readonly LocalWorkbookStore $store,
    ) {
    }

    /**
     * @param  bool  $refresh  force a re-download (used when a cached copy predates the requested year's column)
     */
    public function adjustedIndexHistoryWorkbookPath(bool $refresh = false): string
    {
        $file = $this->files()->category(self::ADJUSTED_INDEX_HISTORY_CATEGORY)->sole();

        return $this->store->ensureLocal($file, $this->directory(), $refresh);
    }

    private function files(): ActOneIndexFiles
    {
        return new ActOneIndexFiles($this->finder, $this->downloader);
    }

    private function directory(): string
    {
        return config('pde-client.act_one_index.download_directory', 'pde-client/act-one-index');
    }
}
