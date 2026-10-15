<?php

namespace WiserWebSolutions\PDEClient\Support;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;

final class WorkbookFingerprint
{
    public const PARSER_VERSION = 4;

    public static function forPaths(string|array $paths): string
    {
        $identities = [];
        foreach ((array) $paths as $path) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new PDEClientException("Could not fingerprint workbook [{$path}].");
            }
            $identities[] = self::source($path).'|'.$hash.'|'.json_encode(SourceReference::forPath($path, $hash)?->toCacheArray(), JSON_THROW_ON_ERROR);
        }

        return hash('sha256', implode('|', $identities));
    }

    public static function source(string $path): string
    {
        $manifest = $path.'.pde-source.json';
        if (is_file($manifest)) {
            $data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);

            return $data['url'] ?? $path;
        }

        return $path;
    }
}
