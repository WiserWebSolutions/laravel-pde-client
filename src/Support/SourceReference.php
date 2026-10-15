<?php

namespace WiserWebSolutions\PDEClient\Support;

use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\RemoteFile;

/** A public document supporting a value, optionally pinned to downloaded bytes. */
#[MapName(SnakeCaseMapper::class)]
final class SourceReference extends Data
{
    public readonly string $id;

    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $pageUrl = null,
        public readonly ?string $period = null,
        public readonly ?string $sha256 = null,
        public readonly ?string $downloadedAt = null,
    ) {
        if (! self::isPublicUrl($url) || ($pageUrl !== null && ! self::isPublicUrl($pageUrl))) {
            throw new InvalidArgumentException('Source references require HTTP or HTTPS URLs.');
        }
        $this->id = hash('sha256', implode('|', [$pageUrl ?? '', $url, $sha256 ?? '']));
    }

    public static function isPublicUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    public static function forFile(RemoteFile $file, ?string $sha256 = null, ?int $downloadedAt = null): self
    {
        return new self($file->label, $file->url, $file->pageUrl, $file->period, $sha256,
            $downloadedAt === null ? null : gmdate('c', $downloadedAt));
    }

    public static function fromArray(array $data): self
    {
        return new self($data['label'], $data['url'], $data['page_url'] ?? $data['pageUrl'] ?? null,
            $data['period'] ?? null, $data['sha256'] ?? null, $data['downloaded_at'] ?? $data['downloadedAt'] ?? null);
    }

    /** Local paths without a public-source manifest do not become citations. */
    public static function forPath(string $path, ?string $contentHash = null): ?self
    {
        $manifestPath = $path.'.pde-source.json';
        if (! is_file($manifestPath)) {
            return self::isPublicUrl($path) ? new self(basename((string) parse_url($path, PHP_URL_PATH)), $path) : null;
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (! self::isPublicUrl($manifest['url'] ?? '')) {
            return null;
        }
        $contentHash ??= hash_file('sha256', $path) ?: null;
        if (! is_string($manifest['sha256'] ?? null) || $contentHash === null || ! hash_equals($manifest['sha256'], $contentHash)) {
            return null;
        }

        return self::forFile(RemoteFile::fromArray([
            'label' => $manifest['label'] ?? basename((string) parse_url($manifest['url'], PHP_URL_PATH)),
            'url' => $manifest['url'], 'page_url' => $manifest['page_url'] ?? null,
            'period' => $manifest['period'] ?? null, 'category' => $manifest['category'] ?? null,
        ]), $manifest['sha256'] ?? null, $manifest['downloaded_at'] ?? null);
    }

    /** Plain serialization also works in standalone parser/cache consumers. */
    public function toCacheArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'url' => $this->url,
            'page_url' => $this->pageUrl, 'period' => $this->period, 'sha256' => $this->sha256,
            'downloaded_at' => $this->downloadedAt];
    }
}
