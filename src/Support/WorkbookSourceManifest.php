<?php

namespace WiserWebSolutions\PDEClient\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use WiserWebSolutions\PDEClient\Exceptions\StorageFailedException;
use WiserWebSolutions\PDEClient\RemoteFile;

/** Shared manifest format for raw downloads and parsed-query workbook storage. */
final class WorkbookSourceManifest
{
    public static function fingerprint(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new StorageFailedException("Could not read [{$path}].");
        }
        try {
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) !== $disk->size($path)) {
                throw new StorageFailedException("Could not fingerprint [{$path}].");
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    public static function write(Filesystem $disk, string $path, array $manifest): void
    {
        if (! $disk->put($path.'.pde-source.json', json_encode($manifest, JSON_THROW_ON_ERROR))) {
            throw new StorageFailedException("Could not store source metadata for [{$path}].");
        }
    }

    public static function record(Filesystem $disk, string $path, RemoteFile $file, string $diskName, ?string $hash = null): array
    {
        $manifest = $file->toArray() + ['disk' => $diskName,
            'sha256' => $hash ?? self::fingerprint($disk, $path), 'downloaded_at' => time()];
        self::write($disk, $path, $manifest);

        return $manifest;
    }
}
