<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Financial data (GFB budgets, AFR actuals)
    |--------------------------------------------------------------------------
    |
    | Grouped under its own key so future PDE data modules (enrollment,
    | assessments, staffing, ...) can sit alongside it without reshuffling
    | this config.
    |
    */
    'financial_data' => [
        'gfb' => [
            'page_url' => env(
                'PDE_GFB_PAGE_URL',
                'https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/general-fund-budget-gfb-data'
            ),
            'download_directory' => 'pde-client/gfb',
        ],

        'afr' => [
            'page_url' => env(
                'PDE_AFR_PAGE_URL',
                'https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/summary-of-annual-financial-report-data/afr-data-detailed'
            ),
            'download_directory' => 'pde-client/afr',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enrollment data (public enrollment, projections, English learners)
    |--------------------------------------------------------------------------
    */
    'enrollment' => [
        'page_url' => env(
            'PDE_ENROLLMENT_PAGE_URL',
            'https://www.pa.gov/agencies/education/data-and-reporting/enrollment'
        ),
        'download_directory' => 'pde-client/enrollment',
    ],

    /*
    |--------------------------------------------------------------------------
    | Assessment data (PSSA, Keystone)
    |--------------------------------------------------------------------------
    */
    'assessment' => [
        'page_url' => env(
            'PDE_ASSESSMENT_PAGE_URL',
            'https://www.pa.gov/agencies/education/data-and-reporting/assessment-reporting'
        ),
        'download_directory' => 'pde-client/assessment',
    ],

    /*
    |--------------------------------------------------------------------------
    | Graduation data (cohort graduation rates, dropouts)
    |--------------------------------------------------------------------------
    */
    'graduation' => [
        'page_url' => env(
            'PDE_GRADUATION_PAGE_URL',
            'https://www.pa.gov/agencies/education/data-and-reporting/high-school-graduation'
        ),
        'download_directory' => 'pde-client/graduation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Personnel data (professional staff summaries)
    |--------------------------------------------------------------------------
    */
    'personnel' => [
        'page_url' => env(
            'PDE_PERSONNEL_PAGE_URL',
            'https://www.pa.gov/agencies/education/data-and-reporting/school-staff/professional-and-support-personnel'
        ),
        'download_directory' => 'pde-client/personnel',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default download disk
    |--------------------------------------------------------------------------
    |
    | Any Laravel filesystem disk (local, s3, ...) configured in your app's
    | config/filesystems.php. Can be overridden per call: ->download(disk: '...').
    |
    */
    'disk' => env('PDE_CLIENT_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Listing page cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a Finder's parsed results are cached before re-fetching the
    | listing page. PDE posts new data a few times a year, so there's no need
    | to scrape on every call.
    |
    */
    'cache_ttl' => env('PDE_CLIENT_CACHE_TTL', 604800),

    /*
    |--------------------------------------------------------------------------
    | Parsed workbook cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Once a downloaded GFB/AFR workbook has been parsed, the extracted
    | year-table is cached so repeated queries don't re-read the spreadsheet.
    | Set to 0 to disable and re-parse on every request.
    |
    */
    'parsed_cache_ttl' => env('PDE_CLIENT_PARSED_CACHE_TTL', 604800),

    /*
    |--------------------------------------------------------------------------
    | Default school district (AUN)
    |--------------------------------------------------------------------------
    |
    | The 9-digit Administrative Unit Number used by financial queries when
    | ->district() is called without an argument (or not called at all).
    |
    */
    'default_district' => env('PDE_CLIENT_DEFAULT_AUN', 124157203),

];
