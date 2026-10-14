<?php

namespace WiserWebSolutions\PDEClient\Tests\Integration;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use WiserWebSolutions\PDEClient\AgentData\ToolDefinitions;
use WiserWebSolutions\PDEClient\AgentData\ToolDispatcher;
use WiserWebSolutions\PDEClient\Mcp\AnalysisPrompt;
use WiserWebSolutions\PDEClient\Mcp\CatalogResource;
use WiserWebSolutions\PDEClient\Mcp\PdeServer;
use WiserWebSolutions\PDEClient\Mcp\PdeTool;
use WiserWebSolutions\PDEClient\Tests\AgentData\AgentToolsTest;
use WiserWebSolutions\PDEClient\Tests\Support\DocumentationFixtures;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class McpIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ...(class_exists(McpServiceProvider::class) ? [McpServiceProvider::class] : [])];
    }

    protected function setUp(): void
    {
        if (! class_exists(Server::class)) {
            $this->markTestSkipped('Laravel MCP is optional.');
        }
        parent::setUp();
        Http::preventStrayRequests();
        DocumentationFixtures::register($this->app);
    }

    public static function datasets(): array
    {
        return AgentToolsTest::endpoints();
    }

    #[DataProvider('datasets')]
    public function test_mcp_tools_return_the_shared_result_for_every_endpoint(string $dataset): void
    {
        $operation = 'get_'.str_replace('-', '_', $dataset);
        $tool = new PdeTool($operation);
        $arguments = ['aun' => DocumentationFixtures::AUN, 'year' => '2024-2025'];
        $expected = app(ToolDispatcher::class)->execute($operation, $arguments);
        $this->assertSame($expected, $tool->handle(new Request($arguments))->getStructuredContent());
        PdeServer::tool($tool, $arguments)->assertOk()->assertStructuredContent($expected);
    }

    public function test_server_resources_prompt_errors_and_read_only_schemas(): void
    {
        PdeServer::resource(CatalogResource::class)->assertOk()->assertSee('gfb-budgets');
        PdeServer::prompt(AnalysisPrompt::class)->assertOk()->assertSee('never guess an identity');
        PdeServer::tool(new PdeTool('get_adm'), ['aun' => 'bad'])->assertHasErrors(['invalid_arguments']);
        foreach (array_keys(app(ToolDefinitions::class)->all()) as $operation) {
            $tool = new PdeTool($operation);
            $this->assertTrue($tool->toArray()['annotations']['readOnlyHint']);
            $this->assertSame($operation, $tool->toArray()['name']);
            JsonSchema::object(fn ($schema) => $tool->schema($schema))->toArray();
        }
    }

    public function test_local_artisan_server_exchanges_clean_json_rpc_on_standard_output(): void
    {
        $autoload = dirname((new \ReflectionClass(Server::class))->getFileName(), 4).'/autoload.php';
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18', 'capabilities' => (object) [],
                'clientInfo' => ['name' => 'pde-test', 'version' => '1.0'],
            ]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_datasets', 'arguments' => (object) []]],
        ];
        $process = new Process([PHP_BINARY, __DIR__.'/mcp-smoke.php', $autoload], dirname(__DIR__, 2));
        $process->setInput(implode("\n", array_map(fn ($message) => json_encode($message), $messages))."\n");
        $process->setTimeout(30);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $this->assertCount(3, $lines, $process->getOutput());
        $responses = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
        foreach ($responses as $response) {
            $this->assertArrayNotHasKey('error', $response, json_encode($response));
        }
        $this->assertCount(24, $responses[1]['result']['tools']);
        $this->assertCount(17, $responses[2]['result']['structuredContent']['data']);
    }
}
