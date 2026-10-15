<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Downloaders\FilesystemDownloader;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\Exceptions\StorageFailedException;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\Finders\GfbFileFinder;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;
use WiserWebSolutions\PDEClient\Support\HttpRequest;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Support\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class HardeningTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_explicit_financial_totals_do_not_count_ancestors_twice(): void
    {
        DocumentationFixtures::register($this->app);
        $query = PDE::district('124157203')->year(2024)->financials();
        $this->assertSame(1000.0, $query->total(Measure::Budget, FinancialCategory::Revenue));
        $this->assertSame(1100.0, $query->total(Measure::Actual, FinancialCategory::Revenue));
        $this->assertSame(2000.0, $query->total(Measure::Budget, FinancialCategory::Expenditure));
    }

    public function test_financial_first_stops_before_loading_the_next_year(): void
    {
        $repository = $this->createMock(FinancialDataRepository::class);
        $repository->method('availableBudgetYears')->willReturn([FiscalYear::parse(2024), FiscalYear::parse(2023)]);
        $repository->expects($this->once())->method('budgetRevenues')->with($this->callback(fn ($year) => $year->startYear === 2023))
            ->willReturn(new YearTable(['124157203' => DocumentationFixtures::DISTRICT], ['124157203' => ['6111' => 100.0]], []));
        $this->app->instance(FinancialDataRepository::class, $repository);
        $result = PDE::district('124157203')->allYears()->financials()->budget()->revenues()->first();
        $this->assertSame('2023-2024', $result->fiscalYear);
    }

    public function test_source_revision_invalidates_memory_and_persistent_parsed_caches(): void
    {
        config(['pde-client.parsed_cache_ttl' => 3600]);
        $path = tempnam(sys_get_temp_dir(), 'pde-cache-test-');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        $cache = app(Cache::class);
        $reader = new class($cache)
        {
            use RemembersParsedRowTables;

            public int $parsed = 0;

            public function __construct(private readonly Cache $cache) {}

            public function table(string $path): RowTable
            {
                return $this->rememberRowTable('test:2024', function () use ($path) {
                    $this->parsed++;

                    return new RowTable([], ['value' => file_get_contents($path)], ['fields' => ['new_metric' => 'not_collected']]);
                }, $path);
            }
        };
        file_put_contents($path, '1');
        $this->assertSame('1', $reader->table($path)->rows['value']);
        $this->assertSame('1', $reader->table($path)->rows['value']);
        $this->assertSame(1, $reader->parsed);
        file_put_contents($path, '2');
        $this->assertSame('2', $reader->table($path)->rows['value']);
        $this->assertSame(2, $reader->parsed);
    }

    public function test_failed_storage_put_does_not_report_success(): void
    {
        Http::fake(['https://fixtures.example/file.xlsx' => Http::response('workbook', 200)]);
        $disk = $this->createStub(Filesystem::class);
        $disk->method('put')->willReturn(false);
        $factory = $this->createStub(FilesystemFactory::class);
        $factory->method('disk')->willReturn($disk);
        $this->expectException(StorageFailedException::class);
        (new FilesystemDownloader(Http::getFacadeRoot(), $factory))->download(new RemoteFile('File', 'https://fixtures.example/file.xlsx'), 'file.xlsx');
    }

    public function test_remote_disk_copies_are_isolated_and_cleaned_up(): void
    {
        $makeDisk = function (string $contents) {
            $disk = $this->createStub(Filesystem::class);
            $disk->method('exists')->willReturn(true);
            $disk->method('get')->willReturn(json_encode((new RemoteFile('File', 'https://fixtures.example/file.xlsx'))->toArray() + [
                'sha256' => hash('sha256', $contents), 'downloaded_at' => time(),
            ]));
            $disk->method('lastModified')->willReturn(time());
            $disk->method('size')->willReturn(strlen($contents));
            $disk->method('readStream')->willReturnCallback(function () use ($contents) {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $contents);
                rewind($stream);

                return $stream;
            });

            return $disk;
        };
        $first = $makeDisk('first disk');
        $second = $makeDisk('second disk');
        $factory = $this->createStub(FilesystemFactory::class);
        $factory->method('disk')->willReturnCallback(fn ($name) => $name === 'first' ? $first : $second);
        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->never())->method('download');
        $store = new LocalWorkbookStore($downloader, $factory);
        $file = new RemoteFile('File', 'https://fixtures.example/file.xlsx');
        config(['pde-client.disk' => 'first']);
        $a = $store->ensureLocal($file, 'files');
        config(['pde-client.disk' => 'second']);
        $b = $store->ensureLocal($file, 'files');
        $this->assertNotSame($a, $b);
        $this->assertSame('first disk', file_get_contents($a));
        $this->assertSame('second disk', file_get_contents($b));
        unset($store);
        $this->assertFileDoesNotExist($a);
        $this->assertFileDoesNotExist($b);
    }

    public function test_empty_discovery_is_not_cached_and_download_query_links_are_found(): void
    {
        Http::fake(['https://fixtures.example/page' => Http::sequence()->push('<p>No files yet</p>')->push('<a href="../gfb/2024-25.XLSX?download=1">2024-25 GFB</a>')]);
        $finder = new GfbFileFinder('https://fixtures.example/page', Http::getFacadeRoot(), app(Cache::class), 3600);
        $this->assertTrue($finder->find()->isEmpty());
        $files = $finder->find();
        $this->assertCount(1, $files);
        $this->assertSame('https://fixtures.example/gfb/2024-25.XLSX?download=1', $files->sole()->url);
    }

    public function test_uri_resolution_retains_ports_and_normalizes_parent_segments(): void
    {
        $document = new HtmlDocument('<p>Fixture</p>', 'https://fixtures.example:8443/reports/page');
        $this->assertSame('https://fixtures.example:8443/file.xlsx?download=1', $document->absoluteUrl('../file.xlsx?download=1'));
    }

    public function test_legacy_xls_formulas_use_cached_results(): void
    {
        $path = $this->xlsFixture(['Data' => [['Value'], ['=1+2']]]);
        $rows = iterator_to_array(app(SpreadsheetReader::class)->rows($path, 'Data'));
        $this->assertEquals(3, $rows[1][0]);
    }

    public function test_nonconsecutive_fiscal_ranges_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FiscalYear::parse('2024-2030');
    }

    public function test_invalid_auns_are_rejected(): void
    {
        $this->expectException(PDEClientException::class);
        PDE::district('1234');
    }

    public function test_changed_source_urls_and_expired_workbooks_are_refetched(): void
    {
        $disk = app(FilesystemFactory::class)->disk('local');
        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->exactly(3))->method('download')->willReturnCallback(function ($file, $path) use ($disk) {
            $disk->put($path, $file->url);

            return $path;
        });
        $store = new LocalWorkbookStore($downloader, app(FilesystemFactory::class));
        $old = new RemoteFile('Old source', 'https://fixtures.example/old/data.xlsx');
        $new = new RemoteFile('New source', 'https://fixtures.example/new/data.xlsx');
        $path = $store->ensureLocal($old, 'source-refresh-test');
        $this->assertSame($old->url, file_get_contents($path));
        $this->assertSame($path, $store->ensureLocal($old, 'source-refresh-test'));
        $this->assertSame($new->url, file_get_contents($store->ensureLocal($new, 'source-refresh-test')));
        $manifestPath = 'source-refresh-test/data.xlsx.pde-source.json';
        $manifest = json_decode($disk->get($manifestPath), true);
        $manifest['downloaded_at'] = 0;
        $disk->put($manifestPath, json_encode($manifest));
        $this->assertSame($new->url, file_get_contents($store->ensureLocal($new, 'source-refresh-test')));
    }

    public function test_http_retries_server_errors_but_keeps_client_errors_visible(): void
    {
        config(['pde-client.http.retry_delay' => 0]);
        Http::fake([
            'https://fixtures.example/transient.xlsx' => Http::sequence()->push('', 503)->push('Workbook', 200),
            'https://fixtures.example/missing.xlsx' => Http::response('', 404),
        ]);
        $request = HttpRequest::make(Http::getFacadeRoot());
        $this->assertSame(200, $request->get('https://fixtures.example/transient.xlsx')->status());
        $this->assertSame(404, HttpRequest::make(Http::getFacadeRoot())->get('https://fixtures.example/missing.xlsx')->status());
        Http::assertSentCount(3);
    }

    public function test_filenames_keep_literal_pluses_and_remove_encoded_path_separators(): void
    {
        $this->assertSame('district+year.xlsx', (new RemoteFile('Fixture', 'https://fixtures.example/district+year.xlsx?download=1'))->filename());
        $this->assertSame('file.xlsx', (new RemoteFile('Fixture', 'https://fixtures.example/..%5Cfile.xlsx'))->filename());
    }
}
