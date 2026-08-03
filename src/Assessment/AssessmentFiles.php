<?php

namespace WiserWebSolutions\PDEClient\Assessment;

use WiserWebSolutions\PDEClient\Assessment\Finders\AssessmentFileFinder;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;

/**
 * Raw file discovery/download over PDE's assessment reporting page - PSSA
 * and Keystone result workbooks at state, district, and school level.
 *
 * PDE::assessmentFiles()->category('pssa')->matching('district')->get();
 */
class AssessmentFiles extends DataSource
{
    public function __construct(AssessmentFileFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.assessment.download_directory', 'pde-client/assessment');
    }
}
