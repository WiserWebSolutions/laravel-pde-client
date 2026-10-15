<?php

namespace WiserWebSolutions\PDEClient\Ai;

use Laravel\Ai\Contracts\Tool;
use LogicException;
use WiserWebSolutions\PDEClient\AgentData\ToolDefinitions;

final class PdeTools
{
    /** @return list<PdeTool> */
    public static function all(): array
    {
        return self::only(array_keys(app(ToolDefinitions::class)->all()));
    }

    /** @param list<string> $operations
     * @return list<PdeTool>
     */
    public static function only(array $operations): array
    {
        if (! interface_exists(Tool::class)) {
            throw new LogicException('Install laravel/ai:^1.2 to use PDE AI tools (PHP 8.3+, Laravel 12–13).');
        }
        $definitions = app(ToolDefinitions::class);
        foreach ($operations as $operation) {
            $definitions->get($operation);
        }

        return array_map(fn ($operation) => new PdeTool($operation), array_values(array_unique($operations)));
    }
}
