<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;
use WiserWebSolutions\PDEClient\FinancialData\Finders\GfbFileFinder;
use WiserWebSolutions\PDEClient\RemoteFile;

/**
 * General Fund Budget (GFB) data — one workbook per school year.
 *
 * PDE::gfb()->schoolYear('2024-25')->sole()->url;
 * PDE::gfb()->latest()->download();
 */
class GfbFinancialData extends DataSource
{
    public function __construct(GfbFileFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    public function schoolYear(string $schoolYear): static
    {
        return $this->matching($schoolYear);
    }

    /**
     * The most recent school year currently published, regardless of the
     * order PDE happens to list them in.
     */
    public function latest(): ?RemoteFile
    {
        return $this->get()
            ->sortByDesc(fn (RemoteFile $file) => $file->period)
            ->first();
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.financial_data.gfb.download_directory', 'pde-client/gfb');
    }
}
