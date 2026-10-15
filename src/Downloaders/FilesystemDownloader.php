<?php

namespace WiserWebSolutions\PDEClient\Downloaders;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Exceptions\DownloadFailedException;
use WiserWebSolutions\PDEClient\Exceptions\StorageFailedException;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\HttpRequest;
use WiserWebSolutions\PDEClient\Support\WorkbookSourceManifest;

/**
 * Downloads a RemoteFile onto a Laravel filesystem disk.
 *
 * These xlsx files can run tens of megabytes, so the response body is
 * streamed straight to a local temp file (Guzzle's "sink" option) rather
 * than buffered in PHP memory, then handed to Storage as a stream - which
 * keeps memory flat whether the destination disk is local or remote (S3,
 * etc.), since Flysystem streams a resource rather than reading it whole.
 */
class FilesystemDownloader implements FileDownloader
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly FilesystemFactory $filesystem,
        private readonly ?int $timeoutSeconds = null,
    ) {}

    public function download(RemoteFile $file, string $path, ?string $disk = null): string
    {
        $disk ??= config('pde-client.disk', 'local');
        $tempPath = tempnam(sys_get_temp_dir(), 'pde-client-');

        if ($tempPath === false) {
            throw new StorageFailedException('Unable to allocate a workbook download file.');
        }

        try {
            $response = HttpRequest::make($this->http, $this->timeoutSeconds)
                ->withOptions(['sink' => $tempPath])
                ->get($file->url);

            if ($response->failed()) {
                throw DownloadFailedException::forFile($file, $response->status());
            }

            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new StorageFailedException('Unable to read the downloaded workbook.');
            }

            try {
                if (! $this->filesystem->disk($disk)->put($path, $stream)) {
                    throw new StorageFailedException("Could not write workbook [{$path}] to disk [{$disk}].");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $hash = hash_file('sha256', $tempPath);
            if ($hash === false) {
                throw new StorageFailedException('Unable to fingerprint the downloaded workbook.');
            }
            WorkbookSourceManifest::record($this->filesystem->disk($disk), $path, $file, $disk, $hash);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return $path;
    }
}
