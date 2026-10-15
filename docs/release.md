# Releasing the next major version

The collection, aggregation, metric-map, and strict historical-data changes require a major release. See [the upgrade guide](upgrade.md).

1. Run `composer validate --strict`, `composer format:check`, `composer analyse`, `composer test`, and `composer audit`.
2. Require the PHP 8.2–8.5 / Laravel 10–13 workflow, including the lowest-dependency job, to pass. Resolve dependencies separately for each framework; a PHP 8.5 development lockfile does not establish PHP 8.2 compatibility.
3. Verify all 17 documented examples. Announce historical schema support only when backed by report fixtures or source evidence; retain synthetic labels on sample values.
   Verify their field-level source citations through the integration suite, and recheck chart resource checksums against the cited accounting manual if those resources change.
4. Update CHANGELOG.md and publish the next major tag through the maintainer’s normal release process.
5. Refresh consumers’ published configuration and follow the upgrade guide. Migration templates remain optional and require review in the consumer application.

No release is published and no consumer database is migrated automatically.

Upstream references: [Laravel Pint](https://laravel.com/docs/13.x/pint), [checkout](https://github.com/actions/checkout), [PHP setup](https://github.com/shivammathur/setup-php), and [PHPStan property extensions](https://phpstan.org/developing-extensions/always-read-written-properties).

Legacy framework compatibility jobs permit upstream advisory versions only in the isolated test environment, so minimum dependencies can be tested. The latest-dependency job keeps Composer blocking enabled and runs composer audit. This is not a recommendation to deploy old dependency sets. [Composer command policy](https://getcomposer.org/doc/03-cli.md#composer-no-security-blocking).

Formatting scripts use a single Pint process for consistent writes on Windows. Pint 1.30.4 is the minimum development tool version and supports PHP 8.2.
