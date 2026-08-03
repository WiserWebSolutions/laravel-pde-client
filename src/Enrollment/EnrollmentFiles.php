<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;
use WiserWebSolutions\PDEClient\Enrollment\Finders\EnrollmentFileFinder;

/**
 * Raw file discovery/download over PDE's enrollment page - public school
 * enrollment, enrollment projections, and English learner counts (plus
 * private/nonpublic, homeschool, and low-income files, categorized but not
 * otherwise modeled yet).
 *
 * PDE::enrollmentFiles()->category('public')->get();
 * PDE::enrollmentFiles()->matching('School District Enrollment Projections')->sole()->url;
 */
class EnrollmentFiles extends DataSource
{
    public function __construct(EnrollmentFileFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.enrollment.download_directory', 'pde-client/enrollment');
    }
}
