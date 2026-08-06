<?php

namespace WiserWebSolutions\PDEClient\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use WiserWebSolutions\PDEClient\PDEClientServiceProvider;

/**
 * Every Finder is bound in PDEClientServiceProvider via a factory closure
 * that reads its page URL straight from config (see the provider's
 * docblock) - so every fake page URL below must be in place before a test
 * resolves a Finder for the first time. defineEnvironment() runs before the
 * container is fully booted, which is early enough.
 *
 * Both cache TTLs are zeroed so each test observes a fresh
 * fetch/parse rather than a stale result left over from another test in the
 * same run - RemembersParsedRowTables/FinancialDataRepository both treat
 * parsed_cache_ttl <= 0 as "don't cache at all". The Finder listing-page
 * cache_ttl has no such escape hatch, but Testbench boots a fresh
 * Application (and therefore a fresh array-driver cache) per test method, so
 * a single test never sees another test's cached listing page regardless.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, string>
     */
    protected function getPackageProviders($app): array
    {
        return [PDEClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pde-client.financial_data.gfb.page_url', 'https://pde-client-tests.example/gfb');
        $app['config']->set('pde-client.financial_data.afr.page_url', 'https://pde-client-tests.example/afr');
        $app['config']->set('pde-client.enrollment.page_url', 'https://pde-client-tests.example/enrollment');
        $app['config']->set('pde-client.assessment.page_url', 'https://pde-client-tests.example/assessment');
        $app['config']->set('pde-client.graduation.page_url', 'https://pde-client-tests.example/graduation');
        $app['config']->set('pde-client.personnel.page_url', 'https://pde-client-tests.example/personnel');
        $app['config']->set('pde-client.financial_data_elements.page_url', 'https://pde-client-tests.example/financial-data-elements');

        $app['config']->set('pde-client.disk', 'local');
        $app['config']->set('pde-client.cache_ttl', 3600);
        $app['config']->set('pde-client.parsed_cache_ttl', 0);
        $app['config']->set('pde-client.default_district', '124157203');

        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => $this->fakeDiskRoot(),
        ]);
    }

    private function fakeDiskRoot(): string
    {
        return sys_get_temp_dir().'/pde-client-tests-'.getmypid();
    }
}
