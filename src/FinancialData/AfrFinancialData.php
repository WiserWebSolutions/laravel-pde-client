<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;
use WiserWebSolutions\PDEClient\FinancialData\Finders\AfrFileFinder;

/**
 * AFR (Annual Financial Report) detailed data — files grouped by category,
 * most spanning a multi-year range rather than one file per year.
 *
 * PDE::afr()->revenues()->download();
 * PDE::afr()->matching('Local Revenue')->sole()->url;
 */
class AfrFinancialData extends DataSource
{
    public function __construct(AfrFileFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    public function revenues(): static
    {
        return $this->category('Revenues');
    }

    public function expenditures(): static
    {
        return $this->category('Expenditures');
    }

    public function miscellaneous(): static
    {
        return $this->category('Miscellaneous');
    }

    /**
     * The standalone full-year AFR workbooks listed before AFR's category
     * headings (e.g. "2024-25 AFR data").
     */
    public function fullReports(): static
    {
        return $this->category(AfrFileFinder::DEFAULT_CATEGORY);
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.financial_data.afr.download_directory', 'pde-client/afr');
    }
}
