<?php

namespace WiserWebSolutions\PDEClient\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use WiserWebSolutions\PDEClient\AgentData\AnalystInstructions;

final class AnalysisPrompt extends Prompt
{
    protected string $name = 'pde_analysis';

    protected string $description = 'Instructions for source-grounded PDE analysis, district resolution, units and missing data.';

    public function handle(Request $request): Response
    {
        return Response::text(AnalystInstructions::TEXT);
    }
}
