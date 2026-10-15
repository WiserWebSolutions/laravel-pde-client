# Optional AI tools and local MCP

[Documentation index](index.md)

The package exposes 24 read-only tools: three discovery tools, 17 dataset tools, and four analysis tools. Laravel AI and MCP share schemas, validation, query builders, calculations, serialization, and errors. Either adapter can be installed independently. The core retains PHP 8.2 and Laravel 10–13 support.

## Laravel AI SDK

Install the optional SDK in your application:

```sh
composer require laravel/ai:^1.2
```

The SDK requires PHP 8.3+ and Laravel 12–13 with its required framework patch versions. Configure provider credentials and defaults in Laravel AI's `config/ai.php`. Follow the [SDK setup documentation](https://laravel.com/docs/13.x/ai-sdk#installation). Conversation migrations are needed when your application chooses persistence; the reference analyst is stateless.

```php
use WiserWebSolutions\PDEClient\Ai\PdeAnalyst;

$response = (new PdeAnalyst)->prompt(
    'Find Example District, then compare its actual revenues for '
    .'2022-2023 and 2023-2024. Ask me to choose if the name is ambiguous.'
);

echo $response->text;
```

The analyst follows SDK provider/model defaults and supports the normal `prompt()`, `stream()`, and queue APIs. Its default budget is 20 steps. Applications own UI, credentials, user permissions, and optional conversation storage.

Add the tools to an existing agent implementing `Laravel\Ai\Contracts\HasTools`:

```php
use WiserWebSolutions\PDEClient\Ai\PdeTools;

public function tools(): iterable
{
    return PdeTools::all();
}
```

Expose a selected set with `PdeTools::only(['list_datasets', 'find_districts', 'get_afr_actuals', 'calculate_total'])`. Unknown operation names are rejected. Data/calculation handlers call no language model; prompting an agent requires a provider.

## Local MCP server

Install MCP independently of the AI SDK:

```sh
composer require laravel/mcp:^1.0
php artisan vendor:publish --tag=ai-routes
```

Register in the consuming application's `routes/ai.php`:

```php
use Laravel\Mcp\Facades\Mcp;
use WiserWebSolutions\PDEClient\Mcp\PdeServer;

Mcp::local('pde', PdeServer::class);
```

Configure the client with its equivalent of:

```json
{
    "mcpServers": {
        "pde": {
            "command": "php",
            "args": ["/absolute/path/to/your/application/artisan", "mcp:start", "pde"]
        }
    }
}
```

Use the PHP executable that runs your application. On Windows, supply an absolute Windows path to `artisan`, escaping backslashes in JSON. The client launches and owns this local process. MCP use requires no AI provider credentials in Laravel. Tools retrieve public PDE workbooks and use normal package caches.

The server exposes all 24 tools, `pde://catalog`, 17 `pde://datasets/{dataset-id}` resources, and the `pde_analysis` prompt. Resources contain definitions; `describe_dataset` discovers available years and report schema. No HTTP route is registered.

Inspect it with `php artisan mcp:inspector pde`.

## Catalog and dataset operations

The catalog works without either optional dependency:

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$definitions = PDE::catalog()->all(); // Metadata only; no downloads.
$assessment = PDE::catalog()->get('pssa');
$years = PDE::catalog()->availableYears('pssa'); // Source discovery can download.
```

`list_datasets` returns all definitions. `describe_dataset` accepts `dataset`, optional dataset filters, and optional explicit `year`. It discovers years and includes observed schema when a year is supplied. A discovered year does not promise every field, demographic, or district is available.

`find_districts` accepts `search` and optional `year`, `limit`, and `cursor`. It searches AUNs and normalized names in that year's public-enrollment workbook, defaulting to the latest available year. Exact matches rank first. Results include AUN strings, name, county, LEA type, and source year. Multiple candidates are explicitly ambiguous. Historical names require the historical year; this source-derived directory is not a complete registry or a crosswalk for unsupported name-only reports.

Each [documented endpoint](index.md) has a tool named `get_` plus its identifier with hyphens replaced by underscores: for example `get_gfb_budgets`, `get_english_learners`, and `get_professional_personnel`.

Dataset tools require a nine-digit string `aun`. They accept optional `year`, `fields` (snake_case names), `limit`, and `cursor`. An omitted year selects one latest available year for that dataset. Results contain flat financial account/enrollment grade records, rather than the nested summaries of ordinary `get()`.

The catalog and schemas list supported filters:

- Financials: `category` and `accounts`.
- Enrollment: `grades`.
- Assessments: `subjects`, `grades`, `groups`.
- Graduation: `cohort_years` (4 by default, or 5/6), `groups`.
- Debt: `fund_types`, `phases`.
- Personnel: `personnel_categories`.
- Tax rates: exact case-insensitive `county` and `notes`.

Unknown arguments, unsupported filters, malformed identifiers, duplicate selections, and invalid limits are errors.

## Deterministic analysis

All analysis requires explicit `years`. Results identify the district/year and published units.

- `calculate_total` requires `dataset`, `aun`, `years`. Financial datasets also require `category`; optional `accounts` restrict the total. Enrollment datasets accept `grades`. Financial totals reuse parent-aware aggregation. Missing explicitly selected accounts/grades make totals unavailable rather than reducing them silently. Enrollment, projections, and English learners remain separate. Other datasets support scalar comparison/trend analysis.
- `budget_variance` requires `aun`, `years`, `category`; optional `accounts`. It returns actual minus budget and relative percent change.
- `trend` requires `dataset`, `aun`, `years` (at least two), and numeric `metric` from the catalog. Filters must select exactly one compatible record per year. Years are chronological and differences use the preceding requested year.
- `compare_districts` requires `dataset`, `auns` (at least two), `years`, and `metric`. The first AUN is the baseline for each year. Filters must select one compatible record per district/year.

Shared handlers are also directly usable without AI dependencies:

```php
use WiserWebSolutions\PDEClient\AgentData\ToolDispatcher;

$result = app(ToolDispatcher::class)->execute('budget_variance', [
    'aun' => '124157203',
    'years' => ['2023-2024'],
    'category' => 'revenue',
    'accounts' => ['6111'],
]);
```

The example illustrates invocation, not asserted availability for the AUN/year.

Differences for percentages and 0–1 fractions use percentage points; native values remain unchanged. Relative `percent_change` is separate and becomes null with `zero_baseline` when the baseline is zero. Missing inputs make calculations unavailable; trends do not interpolate gaps. Overlapping aggregate/grade rows and mismatched dimensions are rejected. Rates and averages are never summed.

## Results, availability, sources, and limits

Every operation returns `status`, `data`, `context`, `units`, `sources`, `warnings`, `pagination`, and `error`. Status is `ok`, `unavailable`, `partial` for incomplete analysis, or `error`.

Dataset rows contain `values`, field-level `availability`, and `sources`. For example, an abbreviated synthetic row:

```json
{
    "values": {"aun": "001234567", "budget": 0, "actual": null},
    "availability": {"aun": "available", "budget": "available", "actual": "not_requested"},
    "sources": [{"url": "https://example.com/source.xlsx", "sha256": null, "downloaded_at": null}]
}
```

Nested metric maps retain literal keys and availability paths. The serializer checks availability before reading properties/maps. Nulls retain `not_collected`, `suppressed`, `not_reported`, `not_requested`, `not_applicable`, or `unknown`. Reported zeroes remain zeroes. Strict DTO reads and Spatie exports retain their existing configured behavior; global missing-data configuration is unchanged.

Sources carry public workbook URLs, SHA-256 fingerprints, and UTC download times from existing manifests. Injected sources without manifests can have null fingerprints/times. Local storage paths and disk credentials are excluded. Parsed caches retain these references under a new parser version.

Operational failures return `operational_error` and are logged through Laravel. They remain distinct from missing data. Input errors carry stable codes and explanations. MCP flags error responses while preserving structured content.

Pagination defaults to 100 rows. Continue with identical arguments and `pagination.next_cursor`; `source_changed` requires restarting. Cursors bind to query and source results. Pagination bounds returned rows; parsing can still process a complete source workbook.

Configure bounds in `config/pde-client.php`:

```php
'agent' => [
    'max_districts' => 5,
    'max_years' => 10,
    'page_size' => 100,
],
```

## Integration tests

Core tests/static analysis work without optional packages. Integration tests skip when their dependency is absent. For a separate local environment containing both:

```sh
composer --working-dir=tests/Integration install
php tests/Integration/vendor/phpunit/phpunit/phpunit --bootstrap tests/Integration/vendor/autoload.php
php tests/Integration/vendor/phpstan/phpstan/phpstan analyse --configuration=tests/Integration/phpstan.neon --autoload-file=tests/Integration/vendor/autoload.php
```

SDK tests use fakes/direct handlers. MCP tests use the server testing API and a real local Artisan process. No paid model calls or live PDE data are needed. CI additionally verifies AI-only, MCP-only, and combined environments.
