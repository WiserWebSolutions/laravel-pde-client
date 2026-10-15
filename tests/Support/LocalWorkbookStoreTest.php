<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Storage;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;
use WiserWebSolutions\PDEClient\Tests\TestCase;

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
        Storage::disk('local')->put('workbooks/a-file.xlsx.pde-source.json', json_encode([
            'url' => $this->file()->url, 'sha256' => hash('sha256', 'already here'), 'downloaded_at' => time(),
        ]));

        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->never())->method('download');

        $store = new LocalWorkbookStore($downloader, $this->app->make(FilesystemFactory::class));

        $localPath = $store->ensureLocal($this->file(), 'workbooks');

        $this->assertSame('already here', file_get_contents($localPath));
        $manifest = json_decode(Storage::disk('local')->get('workbooks/a-file.xlsx.pde-source.json'), true);
        $this->assertSame('A file', $manifest['label']);
        $this->assertArrayHasKey('page_url', $manifest);
    }

    public function test_unverified_or_modified_files_are_downloaded_once_before_being_cited(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('workbooks/a-file.xlsx', 'unverified bytes');
        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->exactly(2))->method('download')->willReturnCallback(function ($file, $path) {
            Storage::disk('local')->put($path, 'verified bytes');

            return $path;
        });
        $store = new LocalWorkbookStore($downloader, $this->app->make(FilesystemFactory::class));
        $path = $store->ensureLocal($this->file(), 'workbooks');
        $this->assertSame('verified bytes', file_get_contents($path));
        $this->assertSame($path, $store->ensureLocal($this->file(), 'workbooks'));
        Storage::disk('local')->put('workbooks/a-file.xlsx', 'modified bytes');
        $this->assertSame('verified bytes', file_get_contents($store->ensureLocal($this->file(), 'workbooks')));
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
