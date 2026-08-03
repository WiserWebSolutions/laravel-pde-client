<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\DataSource;
use WiserWebSolutions\PDEClient\Personnel\Finders\PersonnelFileFinder;

/**
 * Raw file discovery/download over PDE's professional and support personnel
 * page - staff summary reports, individual staff reports, attrition/
 * retention, and vacancy files.
 *
 * PDE::personnelFiles()->category('staff_summary')->get();
 * PDE::personnelFiles()->category('individual')->matching('2025-26')->sole()->download();
 */
class PersonnelFiles extends DataSource
{
    public function __construct(PersonnelFileFinder $finder, FileDownloader $downloader)
    {
        parent::__construct($finder, $downloader);
    }

    protected function defaultDirectory(): string
    {
        return config('pde-client.personnel.download_directory', 'pde-client/personnel');
    }
}
