<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;
use WiserWebSolutions\PDEClient\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LocalWorkbookStoreTest extends TestCase
{
    private function file(): RemoteFile
    {
        return new RemoteFile(label: 'A file', url: 'https://example.com/a-file.xlsx');
    }

    public function test_ensure_local_downloads_when_the_file_is_not_yet_on_disk(): void
    {
        Storage::fake('local');

        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->once())
            ->method('download')
            ->with($this->file(), 'workbooks/a-file.xlsx')
            ->willReturnCallback(function (RemoteFile $file, string $path) {
                Storage::disk('local')->put($path, 'workbook contents');

                return $path;
            });

        $store = new LocalWorkbookStore($downloader, $this->app->make(FilesystemFactory::class));

        $localPath = $store->ensureLocal($this->file(), 'workbooks');

        Storage::disk('local')->assertExists('workbooks/a-file.xlsx');
        $this->assertTrue(is_file($localPath));
        $this->assertSame('workbook contents', file_get_contents($localPath));
    }

    public function test_ensure_local_does_not_redownload_when_already_present(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('workbooks/a-file.xlsx', 'already here');

        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->never())->method('download');

        $store = new LocalWorkbookStore($downloader, $this->app->make(FilesystemFactory::class));

        $localPath = $store->ensureLocal($this->file(), 'workbooks');

        $this->assertSame('already here', file_get_contents($localPath));
    }

    public function test_refresh_forces_a_redownload_even_when_present(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('workbooks/a-file.xlsx', 'stale contents');

        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->once())
            ->method('download')
            ->willReturnCallback(function (RemoteFile $file, string $path) {
                Storage::disk('local')->put($path, 'fresh contents');

                return $path;
            });

        $store = new LocalWorkbookStore($downloader, $this->app->make(FilesystemFactory::class));

        $localPath = $store->ensureLocal($this->file(), 'workbooks', refresh: true);

        $this->assertSame('fresh contents', file_get_contents($localPath));
    }
}
