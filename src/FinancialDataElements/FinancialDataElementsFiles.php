<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;
use WiserWebSolutions\PDEClient\FinancialDataElements\Finders\FinancialDataElementsFinder;

/**
 * Raw file discovery/download over PDE's "Financial Data Elements" page -
 * average daily membership and real estate tax rates (plus aid ratios,
 * personal income, and selected data, categorized but not otherwise modeled
 * yet).
 *
 * PDE::financialDataElementsFiles()->category('average_daily_membership')->get();
 */
class FinancialDataElementsFiles extends DataSource
{
    public function __construct(FinancialDataElementsFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.financial_data_elements.download_directory', 'pde-client/financial-data-elements');
    }
}
