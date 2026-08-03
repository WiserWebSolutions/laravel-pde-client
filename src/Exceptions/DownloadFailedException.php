<?php

namespace WiserWebSolutions\PDEClient\Exceptions;

use WiserWebSolutions\PDEClient\RemoteFile;

class DownloadFailedException extends PDEClientException
{
    public static function forFile(RemoteFile $file, int $status): self
    {
        return new self("Failed to download [{$file->label}] from [{$file->url}] - server responded with status {$status}.");
    }
}
