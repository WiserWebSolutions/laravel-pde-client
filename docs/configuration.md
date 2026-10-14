# Configuration

[Documentation index](index.md)

Publish the standard Laravel package configuration:

```sh
php artisan vendor:publish --tag=pde-client-config
```

Configure environment values in the application .env file; application code reads config(), which remains compatible with config:cache.

```dotenv
PDE_CLIENT_DEFAULT_AUN=124157203
PDE_CLIENT_MISSING_DATA=throw
PDE_CLIENT_DISK=local
PDE_CLIENT_CACHE_TTL=604800
PDE_CLIENT_PARSED_CACHE_TTL=604800
PDE_CLIENT_DOWNLOAD_CACHE_TTL=604800
```

- missing_data: throw (default) or return_null. Invalid values fail explicitly. Use return_null rather than the reserved .env literal null.
- default_district: null by default; explicitly select a nine-digit AUN or configure one.
- disk: any configured Laravel filesystem disk. Raw download() can override it per call.
- cache_ttl: listing-page TTL in seconds; values ≤ 0 disable persistent listing caching. Empty discoveries are not cached.
- parsed_cache_ttl: parsed-table and available-year TTL; values ≤ 0 disable persistent parsing caches. Request/query memoization can still avoid duplicate work.
- download_cache_ttl: how long an existing downloaded workbook is reused before re-fetching. Values ≤ 0 disable reuse. Revised bytes and parser versions change parsed-cache keys.
- http.timeout, http.connect_timeout, http.attempts, http.retry_delay: request limits, attempts, and delay in milliseconds. Only connections, HTTP 429, and server errors are retried. Other failures remain errors.

Per-module page_url and download_directory keys live under financial_data.gfb, financial_data.afr, enrollment, assessment, graduation, personnel, financial_data_elements, and act_one_index. Nested overrides preserve unspecified defaults.

```php
config([
    'pde-client.missing_data' => 'return_null',
    'pde-client.http.timeout' => 120,
    'pde-client.http.attempts' => 3,
]);
```

DTOs snapshot the configured policy at construction. Fetch new DTOs after changing it. Availability facts in parsed caches do not contain this policy.
