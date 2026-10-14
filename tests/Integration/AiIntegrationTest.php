<?php

namespace WiserWebSolutions\PDEClient\Tests\Integration;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use WiserWebSolutions\PDEClient\AgentData\ToolDefinitions;
use WiserWebSolutions\PDEClient\AgentData\ToolDispatcher;
use WiserWebSolutions\PDEClient\Ai\PdeAnalyst;
use WiserWebSolutions\PDEClient\Ai\PdeTools;
use WiserWebSolutions\PDEClient\Tests\AgentData\AgentToolsTest;
use WiserWebSolutions\PDEClient\Tests\Support\DocumentationFixtures;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class AiIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ...(class_exists(AiServiceProvider::class) ? [AiServiceProvider::class] : [])];
    }

    protected function setUp(): void
    {
        if (! interface_exists(Tool::class)) {
            $this->markTestSkipped('The Laravel AI SDK is optional.');
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
    public function test_ai_tools_return_the_shared_result_for_every_endpoint(string $dataset): void
    {
        $operation = 'get_'.str_replace('-', '_', $dataset);
        $tool = PdeTools::only([$operation])[0];
        $arguments = ['aun' => DocumentationFixtures::AUN, 'year' => '2024-2025'];
        $actual = json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
        $expected = json_decode(json_encode(app(ToolDispatcher::class)->execute($operation, $arguments)), true);
        $this->assertSame($expected, $actual);
        $this->assertSame($operation, $tool->name());
    }

    public function test_all_tool_schemas_are_valid_and_names_are_unique(): void
    {
        $tools = PdeTools::all();
        $this->assertCount(24, $tools);
        $this->assertCount(24, array_unique(array_map(fn ($tool) => $tool->name(), $tools)));
        foreach ($tools as $tool) {
            $schema = JsonSchema::object(fn ($schema) => $tool->schema($schema))->toArray();
            $this->assertNotEmpty($tool->description());
            foreach (app(ToolDefinitions::class)->get($tool->name())['properties'] as $name => $definition) {
                $this->assertSame($definition['type'], $schema['properties'][$name]['type']);
                if ($definition['required'] ?? false) {
                    $this->assertContains($name, $schema['required']);
                }
            }
        }
        $invalid = json_decode(PdeTools::only(['get_adm'])[0]->handle(new Request(['aun' => 'bad'])), true);
        $this->assertSame('invalid_arguments', $invalid['error']['code']);
    }

    public function test_reference_analyst_uses_sdk_defaults_and_can_be_faked_without_paid_calls(): void
    {
        PdeAnalyst::fake(['Synthetic analysis.'])->preventStrayPrompts();
        $agent = new PdeAnalyst;
        $this->assertStringContainsString('source workbook URLs', $agent->instructions());
        $this->assertCount(24, $agent->tools());
        $this->assertSame('Synthetic analysis.', $agent->prompt('Analyze this district.')->text);
        PdeAnalyst::assertPrompted('Analyze this district.');
    }
}
