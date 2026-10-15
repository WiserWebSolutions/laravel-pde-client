<?php

namespace WiserWebSolutions\PDEClient\Ai;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use WiserWebSolutions\PDEClient\AgentData\AnalystInstructions;

if (! interface_exists(Agent::class)) {
    throw new \LogicException('Install laravel/ai:^1.2 to use the PDE analyst.');
}

#[MaxSteps(20)]
class PdeAnalyst implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return AnalystInstructions::TEXT;
    }

    public function tools(): iterable
    {
        return PdeTools::all();
    }
}
