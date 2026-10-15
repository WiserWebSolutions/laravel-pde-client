<?php

namespace WiserWebSolutions\PDEClient\Mcp;

use Laravel\Mcp\Server;
use WiserWebSolutions\PDEClient\AgentData\AnalystInstructions;
use WiserWebSolutions\PDEClient\AgentData\DatasetCatalog;
use WiserWebSolutions\PDEClient\AgentData\ToolDefinitions;

if (! class_exists(Server::class)) {
    throw new \LogicException('Install laravel/mcp:^1.0 to use the local PDE MCP server.');
}

class PdeServer extends Server
{
    protected string $name = 'Pennsylvania Education Data';

    protected string $version = '1.0.0';

    public int $defaultPaginationLength = 50;

    protected string $instructions = AnalystInstructions::TEXT;

    protected function boot(): void
    {
        $this->tools = array_map(fn ($name) => new PdeTool($name), array_keys(app(ToolDefinitions::class)->all()));
        $this->resources = [new CatalogResource, ...array_map(fn ($id) => new DatasetResource($id), app(DatasetCatalog::class)->ids())];
        $this->prompts = [new AnalysisPrompt];
    }
}
