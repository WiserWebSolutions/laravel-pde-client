<?php

namespace WiserWebSolutions\PDEClient\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use WiserWebSolutions\PDEClient\AgentData\ToolDefinitions;
use WiserWebSolutions\PDEClient\AgentData\ToolDispatcher;
use WiserWebSolutions\PDEClient\Integrations\JsonSchemaBuilder;

final class PdeTool implements Tool
{
    public function __construct(private readonly string $operation) {}

    public function name(): string
    {
        return $this->operation;
    }

    public function description(): string
    {
        return app(ToolDefinitions::class)->get($this->operation)['description'];
    }

    public function schema(JsonSchema $schema): array
    {
        return JsonSchemaBuilder::build($schema, app(ToolDefinitions::class)->get($this->operation)['properties']);
    }

    public function handle(Request $request): string
    {
        return json_encode(app(ToolDispatcher::class)->execute($this->operation, $request->all()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
