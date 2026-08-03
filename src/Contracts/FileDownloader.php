<?php

namespace WiserWebSolutions\PDEClient\Contracts;

use WiserWebSolutions\PDEClient\RemoteFile;

/**
 * Downloads a RemoteFile to a Laravel filesystem disk.
 */
interface FileDownloader
{
    /**
     * @param  string  $path  destination path, relative to the disk's root
     * @param  string|null  $disk  filesystem disk name; implementations should fall back to a sensible default when null
     * @return string the path the file was stored at (same as $path, returned for chaining convenience)
     */
    public function download(RemoteFile $file, string $path, ?string $disk = null): string;
}
