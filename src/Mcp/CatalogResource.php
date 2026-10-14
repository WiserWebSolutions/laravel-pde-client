<?php

namespace WiserWebSolutions\PDEClient\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use WiserWebSolutions\PDEClient\AgentData\DatasetCatalog;

final class CatalogResource extends Resource
{
    protected string $uri = 'pde://catalog';

    protected string $mimeType = 'application/json';

    protected string $description = 'Supported PDE datasets, fields, units, filters and source pages. Use describe_dataset for live year/schema discovery.';

    public function handle(Request $request): Response
    {
        return Response::text(json_encode(app(DatasetCatalog::class)->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
