<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;
use WiserWebSolutions\PDEClient\FinancialDataElements\Finders\ActOneIndexFinder;

/**
 * Raw file discovery/download over PDE's "Act 1 Index" page - the adjusted
 * index history workbook (per-district, multi-year) this package models,
 * plus the current year's standalone listing and the statewide base index
 * history, discoverable/downloadable but not otherwise modeled.
 *
 * PDE::actOneIndexFiles()->category('adjusted_index_history')->sole();
 */
class ActOneIndexFiles extends DataSource
{
    public function __construct(ActOneIndexFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.act_one_index.download_directory', 'pde-client/act-one-index');
    }
}
