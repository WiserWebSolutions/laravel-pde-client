<?php

namespace WiserWebSolutions\PDEClient\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\RemoteFile;

/**
 * Downloads a RemoteFile to the configured disk if it isn't there yet, then
 * returns an absolute local filesystem path a spreadsheet reader can open -
 * copying to a temp file when the disk isn't locally mounted (e.g. s3).
 *
 * Shared by every dataset's file locator (financial, enrollment, ...) so the
 * "have we already got this workbook, and how do we get a real path to it
 * regardless of disk driver" logic exists in exactly one place.
 */
class LocalWorkbookStore
{
    public function __construct(
        private readonly FileDownloader $downloader,
        private readonly FilesystemFactory $filesystem,
    ) {
    }

    /**
     * @param  bool  $refresh  force a re-download even if a copy already exists on disk
     */
    public function ensureLocal(RemoteFile $file, string $directory, bool $refresh = false): string
    {
        $disk = $this->filesystem->disk(config('pde-client.disk', 'local'));
        $storagePath = rtrim($directory, '/').'/'.$file->filename();

        if ($refresh || ! $disk->exists($storagePath)) {
            $this->downloader->download($file, $storagePath);
        }

        try {
            $localPath = $disk->path($storagePath);

            if (is_file($localPath)) {
                return $localPath;
            }
        } catch (Throwable) {
            // Disk has no local path (remote adapter) - fall through to a temp copy.
        }

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pde-client-'.md5($storagePath).'-'.$file->filename();

        if ($refresh || ! is_file($tempPath)) {
            $stream = $disk->readStream($storagePath);
            $target = fopen($tempPath, 'w');
            stream_copy_to_stream($stream, $target);
            fclose($target);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $tempPath;
    }
}
