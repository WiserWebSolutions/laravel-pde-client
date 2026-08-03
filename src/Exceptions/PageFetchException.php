<?php

namespace WiserWebSolutions\PDEClient\Exceptions;

class PageFetchException extends PDEClientException
{
    public static function requestFailed(string $url, int $status): self
    {
        return new self("Failed to fetch listing page [{$url}] - server responded with status {$status}.");
    }

    public static function connectionError(string $url, string $reason): self
    {
        return new self("Failed to fetch listing page [{$url}] - {$reason}.");
    }
}
