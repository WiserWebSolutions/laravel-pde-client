<?php

use Illuminate\Contracts\Console\Kernel;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Spatie\LaravelData\LaravelDataServiceProvider;
use WiserWebSolutions\PDEClient\Mcp\PdeServer;
use WiserWebSolutions\PDEClient\PDEClientServiceProvider;

// A real local MCP command: stdout is reserved for protocol messages.
require $argv[1];

$app = Application::create(options: [
    'extra' => [
        'providers' => [
            LaravelDataServiceProvider::class,
            PDEClientServiceProvider::class,
            McpServiceProvider::class,
        ],
        'dont-discover' => ['*'],
    ],
]);
Mcp::local('pde', PdeServer::class);
$exit = $app->make(Kernel::class)->call('mcp:start', ['handle' => 'pde']);
$app->terminate();
exit($exit);
