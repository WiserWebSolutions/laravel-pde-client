<?php

namespace WiserWebSolutions\PDEClient\Downloaders;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Exceptions\DownloadFailedException;
use WiserWebSolutions\PDEClient\RemoteFile;

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
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    public function download(RemoteFile $file, string $path, ?string $disk = null): string
    {
        $disk ??= config('pde-client.disk', 'local');
        $tempPath = tempnam(sys_get_temp_dir(), 'pde-client-');

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withOptions(['sink' => $tempPath])
                ->get($file->url);

            if ($response->failed()) {
                throw DownloadFailedException::forFile($file, $response->status());
            }

            $stream = fopen($tempPath, 'r');

            try {
                $this->filesystem->disk($disk)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return $path;
    }
}
