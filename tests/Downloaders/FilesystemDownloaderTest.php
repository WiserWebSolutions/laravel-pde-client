<?php

namespace WiserWebSolutions\PDEClient\Tests\Downloaders;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use WiserWebSolutions\PDEClient\Downloaders\FilesystemDownloader;
use WiserWebSolutions\PDEClient\Exceptions\DownloadFailedException;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class FilesystemDownloaderTest extends TestCase
{
    public function test_download_streams_the_response_body_to_the_target_disk_path(): void
    {
        Storage::fake('local');
        Http::fake([
            '*' => Http::response('workbook binary contents'),
        ]);

        $downloader = new FilesystemDownloader(
            $this->app->make(HttpFactory::class),
            $this->app->make(FilesystemFactory::class),
        );

        $file = new RemoteFile(label: 'A file', url: 'https://example.com/a-file.xlsx');

        $path = $downloader->download($file, 'workbooks/a-file.xlsx');

        $this->assertSame('workbooks/a-file.xlsx', $path);
        Storage::disk('local')->assertExists('workbooks/a-file.xlsx');
        $this->assertSame('workbook binary contents', Storage::disk('local')->get('workbooks/a-file.xlsx'));
    }

    public function test_download_throws_download_failed_exception_on_failed_response(): void
    {
        Storage::fake('local');
        Http::fake([
            '*' => Http::response('', 404),
        ]);

        $downloader = new FilesystemDownloader(
            $this->app->make(HttpFactory::class),
            $this->app->make(FilesystemFactory::class),
        );

        $file = new RemoteFile(label: 'A file', url: 'https://example.com/missing.xlsx');

        $this->expectException(DownloadFailedException::class);
        $this->expectExceptionMessage('responded with status 404');

        $downloader->download($file, 'workbooks/missing.xlsx');

        Storage::disk('local')->assertMissing('workbooks/missing.xlsx');
    }
}
