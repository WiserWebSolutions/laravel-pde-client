# wiserwebsolutions/laravel-pde-client

A Laravel client for discovering, downloading, and querying Pennsylvania Department of Education data. Supported domains are financials, enrollment, assessments, graduation, and professional personnel.

## Installation

```sh
composer require wiserwebsolutions/laravel-pde-client
php artisan vendor:publish --tag=pde-client-config
```

Laravel discovers the provider and PDE facade. PHP 8.2+ and Laravel 10–13 APIs are supported; resolve dependencies for the target PHP/Laravel version.

## Query data

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$summaries = PDE::district('124157203')->year('2024-2025')
    ->financials()->budget()->revenues()->get();

$enrollment = PDE::district('124157203')->year('2024-2025')
    ->enrollments()->sole();

$results = PDE::district('124157203')->year('2024-2025')
    ->assessments()->pssa()->allStudents()->get();
```

get() always returns a collection. first()/sole() select one DTO; financial and enrollment DTOs are year summaries. records() streams flat account/grade records for imports. Omitting a year selects the most recent available year; allYears() opts into history. Select/configure a nine-digit AUN.

Historical fields known to be omitted from a report throw on access by default. Set PDE_CLIENT_MISSING_DATA=return_null to opt into nulls. Suppressed, blank, and unrequested values remain nullable. Availability helpers explain the reason; serialization follows the same policy.

## Documentation

[Documentation index: all 17 dataset endpoints](docs/index.md) includes complete access chains, field definitions, synthetic PHP/JSON examples, and historical limitations.

- [Configuration](docs/configuration.md)
- [Queries and explicit totals](docs/queries.md)
- [Historical and missing data](docs/missing-data.md)
- [All eight raw file accessors](docs/files.md)
- [Next-major upgrade guide](docs/upgrade.md)
- [Release checks](docs/release.md)
- [Feature roadmap](docs/roadmap.md)

community() is an unsupported placeholder and throws when queried.

## Development

```sh
composer test
composer format:check
composer analyse
composer audit
```

The test suite uses synthetic fixture workbooks and validates every documented endpoint example. The compatibility workflow covers supported PHP/Laravel combinations and minimum dependencies. [Migration templates](migrations/README.md) and [storage guidance](STORING_PDE_DATA.md) are optional consumer references; no consumer database is migrated automatically.

MIT licensed.
