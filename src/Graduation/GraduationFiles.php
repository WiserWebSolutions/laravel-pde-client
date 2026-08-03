<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;
use WiserWebSolutions\PDEClient\Graduation\Finders\GraduationFileFinder;

/**
 * Raw file discovery/download over PDE's high school graduation page -
 * cohort graduation rates and dropout summaries.
 *
 * PDE::graduationFiles()->category('cohort')->matching('4-year')->get();
 */
class GraduationFiles extends DataSource
{
    public function __construct(GraduationFileFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.graduation.download_directory', 'pde-client/graduation');
    }
}
