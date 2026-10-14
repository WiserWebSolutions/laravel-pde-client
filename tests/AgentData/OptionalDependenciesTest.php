<?php

namespace WiserWebSolutions\PDEClient\Tests\AgentData;

use Laravel\Ai\Contracts\Tool;
use LogicException;
use WiserWebSolutions\PDEClient\Ai\PdeTools;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class OptionalDependenciesTest extends TestCase
{
    public function test_core_catalog_loads_without_optional_packages(): void
    {
        $this->assertCount(17, PDE::catalog()->all());
    }

    public function test_requesting_ai_tools_without_the_sdk_explains_the_missing_dependency(): void
    {
        if (interface_exists(Tool::class)) {
            $this->markTestSkipped('This assertion runs in the core/MCP-only dependency jobs.');
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Install laravel/ai:^1.2');
        PdeTools::all();
    }
}
