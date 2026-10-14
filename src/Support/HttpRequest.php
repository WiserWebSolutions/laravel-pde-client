<?php

namespace WiserWebSolutions\PDEClient\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

final class HttpRequest
{
    public static function make(Factory $http, ?int $timeout = null): PendingRequest
    {
        return $http->timeout($timeout ?? (int) config('pde-client.http.timeout', 120))
            ->connectTimeout((int) config('pde-client.http.connect_timeout', 10))
            ->retry(max(1, (int) config('pde-client.http.attempts', 3)), (int) config('pde-client.http.retry_delay', 200),
                static fn (Throwable $exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->status() === 429 || $exception->response->serverError())),
                throw: false,
            );
    }
}
