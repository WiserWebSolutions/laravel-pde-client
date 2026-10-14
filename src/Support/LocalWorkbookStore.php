<?php

namespace WiserWebSolutions\PDEClient\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Exceptions\StorageFailedException;
use WiserWebSolutions\PDEClient\RemoteFile;

class LocalWorkbookStore
{
    private array $temporaryPaths = [];

    private array $localCopies = [];

    public function __construct(private readonly FileDownloader $downloader, private readonly FilesystemFactory $filesystem) {}

    public function ensureLocal(RemoteFile $file, string $directory, bool $refresh = false): string
    {
        $diskName = config('pde-client.disk', 'local');
        $disk = $this->filesystem->disk($diskName);
        $storagePath = rtrim($directory, '/').'/'.$file->filename();
        $manifestPath = $storagePath.'.pde-source.json';
        $manifest = $disk->exists($manifestPath) ? json_decode($disk->get($manifestPath), true, flags: JSON_THROW_ON_ERROR) : null;
        $ttl = (int) config('pde-client.download_cache_ttl', 604800);
        $exists = $disk->exists($storagePath);
        $expired = $exists && ($ttl <= 0 || time() - ($manifest['downloaded_at'] ?? $disk->lastModified($storagePath)) >= $ttl);
        $replacedSource = $manifest !== null && ($manifest['url'] ?? null) !== $file->url;

        if ($refresh || ! $exists || $expired || $replacedSource) {
            $this->downloader->download($file, $storagePath, $diskName);
            if (! $disk->exists($storagePath)) {
                throw new StorageFailedException("Workbook download did not create [{$storagePath}].");
            }
            $input = $disk->readStream($storagePath);
            if (! is_resource($input)) {
                throw new StorageFailedException("Could not read [{$storagePath}].");
            }
            try {
                $hash = hash_init('sha256');
                if (hash_update_stream($hash, $input) !== $disk->size($storagePath)) {
                    throw new StorageFailedException("Could not fingerprint [{$storagePath}].");
                }
                $manifest = ['url' => $file->url, 'disk' => $diskName, 'sha256' => hash_final($hash), 'downloaded_at' => time()];
            } finally {
                fclose($input);
            }
            if (! $disk->put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR))) {
                throw new StorageFailedException("Could not store source metadata for [{$storagePath}].");
            }
        }

        $localPath = $disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter
            ? $disk->path($storagePath)
            : null;
        if ($localPath !== null && is_file($localPath)) {
            return $localPath;
        }

        $identity = hash('sha256', serialize([$diskName, config('filesystems.disks.'.$diskName), $file->url, $storagePath, $manifest['sha256'] ?? null, $disk->lastModified($storagePath), $disk->size($storagePath)]));
        if (! $refresh && isset($this->localCopies[$identity]) && is_file($this->localCopies[$identity])) {
            return $this->localCopies[$identity];
        }
        $allocated = tempnam(sys_get_temp_dir(), 'pde-client-');
        if ($allocated === false) {
            throw new StorageFailedException('Unable to allocate a local workbook copy.');
        }
        $tempPath = $allocated.'-'.substr($identity, 0, 12).'-'.bin2hex(random_bytes(16)).'.'.strtolower(pathinfo($file->filename(), PATHINFO_EXTENSION));
        if (! rename($allocated, $tempPath)) {
            @unlink($allocated);
            throw new StorageFailedException('Unable to name the local workbook copy.');
        }
        $this->temporaryPaths[] = $tempPath;
        $source = $target = null;
        try {
            $source = $disk->readStream($storagePath);
            $target = fopen($tempPath, 'wb');
            if (! is_resource($source) || ! is_resource($target) || stream_copy_to_stream($source, $target) !== $disk->size($storagePath)) {
                throw new StorageFailedException("Could not copy [{$storagePath}] from disk [{$diskName}].");
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
        }
        $sourcePath = $tempPath.'.pde-source.json';
        $this->temporaryPaths[] = $sourcePath;
        if (file_put_contents($sourcePath, json_encode($manifest ?? ['url' => $file->url, 'disk' => $diskName], JSON_THROW_ON_ERROR)) === false) {
            throw new StorageFailedException('Unable to record the local copy source.');
        }

        return $this->localCopies[$identity] = $tempPath;
    }

    public function __destruct()
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }
    }
}
