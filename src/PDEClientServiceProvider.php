<?php

namespace WiserWebSolutions\PDEClient;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use WiserWebSolutions\PDEClient\Assessment\Finders\AssessmentFileFinder;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Downloaders\FilesystemDownloader;
use WiserWebSolutions\PDEClient\Enrollment\Finders\EnrollmentFileFinder;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;
use WiserWebSolutions\PDEClient\FinancialData\Finders\AfrFileFinder;
use WiserWebSolutions\PDEClient\FinancialData\Finders\GfbFileFinder;
use WiserWebSolutions\PDEClient\FinancialDataElements\Finders\ActOneIndexFinder;
use WiserWebSolutions\PDEClient\FinancialDataElements\Finders\FinancialDataElementsFinder;
use WiserWebSolutions\PDEClient\Graduation\Finders\GraduationFileFinder;
use WiserWebSolutions\PDEClient\Personnel\Finders\PersonnelFileFinder;

class PDEClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pde-client.php', 'pde-client');

        $this->app->bind(FileDownloader::class, FilesystemDownloader::class);

        // Each Finder needs a page URL (scalar config), which the container
        // can't autowire on its own - it gets its own factory binding here so
        // everything built on top of it (GfbFinancialData, AfrFinancialData)
        // still resolves automatically.
        $this->app->bind(GfbFileFinder::class, fn (Application $app) => new GfbFileFinder(
            pageUrl: $app['config']->get('pde-client.financial_data.gfb.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->bind(AfrFileFinder::class, fn (Application $app) => new AfrFileFinder(
            pageUrl: $app['config']->get('pde-client.financial_data.afr.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->bind(EnrollmentFileFinder::class, fn (Application $app) => new EnrollmentFileFinder(
            pageUrl: $app['config']->get('pde-client.enrollment.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->bind(AssessmentFileFinder::class, fn (Application $app) => new AssessmentFileFinder(
            pageUrl: $app['config']->get('pde-client.assessment.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->bind(GraduationFileFinder::class, fn (Application $app) => new GraduationFileFinder(
            pageUrl: $app['config']->get('pde-client.graduation.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->bind(PersonnelFileFinder::class, fn (Application $app) => new PersonnelFileFinder(
            pageUrl: $app['config']->get('pde-client.personnel.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->bind(FinancialDataElementsFinder::class, fn (Application $app) => new FinancialDataElementsFinder(
            pageUrl: $app['config']->get('pde-client.financial_data_elements.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->bind(ActOneIndexFinder::class, fn (Application $app) => new ActOneIndexFinder(
            pageUrl: $app['config']->get('pde-client.act_one_index.page_url'),
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            cacheTtlSeconds: $app['config']->get('pde-client.cache_ttl'),
        ));

        $this->app->singleton(ChartOfAccounts::class, fn () => new ChartOfAccounts(
            resourcePath: __DIR__.'/../resources/chart-of-accounts',
        ));

        $this->app->singleton('pde-client', fn (Application $app) => new PDEClientManager($app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/pde-client.php' => config_path('pde-client.php'),
            ], 'pde-client-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'pde-client',
            FileDownloader::class,
            GfbFileFinder::class,
            AfrFileFinder::class,
            EnrollmentFileFinder::class,
            AssessmentFileFinder::class,
            GraduationFileFinder::class,
            PersonnelFileFinder::class,
            FinancialDataElementsFinder::class,
            ActOneIndexFinder::class,
            ChartOfAccounts::class,
        ];
    }
}
