<?php

namespace WiserWebSolutions\PDEClient\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use WiserWebSolutions\PDEClient\AgentData\DatasetCatalog;

final class DatasetResource extends Resource
{
    protected string $mimeType = 'application/json';

    public function __construct(private readonly string $dataset) {}

    public function name(): string
    {
        return $this->dataset;
    }

    public function uri(): string
    {
        return 'pde://datasets/'.$this->dataset;
    }

    public function description(): string
    {
        return app(DatasetCatalog::class)->get($this->dataset)['description'];
    }

    public function handle(Request $request): Response
    {
        return Response::text(json_encode(app(DatasetCatalog::class)->get($this->dataset), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
