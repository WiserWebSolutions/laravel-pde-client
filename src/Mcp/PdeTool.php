<?php

namespace WiserWebSolutions\PDEClient\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use WiserWebSolutions\PDEClient\AgentData\ToolDefinitions;
use WiserWebSolutions\PDEClient\AgentData\ToolDispatcher;
use WiserWebSolutions\PDEClient\Integrations\JsonSchemaBuilder;

#[IsReadOnly]
final class PdeTool extends Tool
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

    public function handle(Request $request): ResponseFactory
    {
        $result = app(ToolDispatcher::class)->execute($this->operation, $request->all());

        return $result['status'] === 'error'
            ? Response::make(Response::error(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))->withStructuredContent($result)
            : Response::structured($result);
    }
}
