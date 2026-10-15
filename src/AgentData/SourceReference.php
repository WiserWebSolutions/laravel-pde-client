<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use WiserWebSolutions\PDEClient\Support\WorkbookFingerprint;

final class SourceReference
{
    public static function forPaths(string|array $paths): array
    {
        $sources = [];
        foreach ((array) $paths as $path) {
            $manifest = is_file($path.'.pde-source.json')
                ? json_decode(file_get_contents($path.'.pde-source.json'), true, flags: JSON_THROW_ON_ERROR)
                : [];
            $sources[] = [
                'url' => $manifest['url'] ?? WorkbookFingerprint::source($path),
                'sha256' => $manifest['sha256'] ?? hash_file('sha256', $path),
                'downloaded_at' => isset($manifest['downloaded_at']) ? gmdate('c', $manifest['downloaded_at']) : null,
            ];
        }

        return self::unique($sources);
    }

    public static function unique(array $sources): array
    {
        $result = [];
        foreach ($sources as $source) {
            // Local fixture paths and storage internals are never public citations.
            if (! is_string($source['url'] ?? null) || ! filter_var($source['url'], FILTER_VALIDATE_URL)
                || ! in_array(parse_url($source['url'], PHP_URL_SCHEME), ['https', 'http'], true)) {
                continue;
            }
            $result[json_encode($source, JSON_THROW_ON_ERROR)] = $source;
        }

        return array_values($result);
    }

    public static function forTable(object $table): array
    {
        return self::unique($table->sources ?: ($table->source === null ? [] : [
            ['url' => $table->source, 'sha256' => null, 'downloaded_at' => null],
        ]));
    }
}
