# Public PDE source citations

[Documentation index](index.md)

Every parsed record and year summary includes `provenance` in its ordinary array and JSON exports. It identifies the public listing page, the exact downloaded file, and the fields that each source supports. Source inspection uses stored metadata and makes no HTTP requests.

## Find the source of a value

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$summary = PDE::district('124157203')->year('2024-2025')
    ->financials()->budget()->revenues()->account('6111')->sole();
$account = $summary->accounts->sole();

foreach ($account->sourcesFor('budget') as $source) {
    $source->label;        // PDE's published file label
    $source->pageUrl;      // Public page where the file was discovered
    $source->url;          // Exact public file URL
    $source->period;       // Published report period, when known
    $source->sha256;       // Fingerprint of the downloaded bytes, when known
    $source->downloadedAt; // Retrieval time in UTC, when known
}
```

`sourcesFor()` returns a list of immutable `Support\SourceReference` objects. It accepts either PHP field names or serialized names, such as `englishLearnersCount` and `english_learners_count`. Unknown fields are errors. It can inspect an uncollected or suppressed field without reading its guarded value; ordinary property access and full exports retain the configured missing-data policy.

For metric maps, use the literal source key: `$record->subCounts->sourcesFor('K5F')`. Each key inherits the references of its containing map field.

## Combined results and derived values

Budget and actual fields retain their own workbook references. Enrollment counts, projections, English learner counts, and economically disadvantaged counts likewise retain separate references. Summaries include the union of references from their contained records, while `sourcesFor('enrollmentTotal')`, for example, identifies only the inputs to that total.

Calculated rollups and totals cite their contributing inputs. A published account amount uses its own workbook; a rollup computed from children can cite multiple workbooks and the chart hierarchy used to combine them. A source citation describes the inputs to a calculation and does not imply that PDE published the calculated result directly.

Existing scalar methods keep returning numbers or null. Choose their sourced variants when exporting or sharing a calculation:

```php
use WiserWebSolutions\PDEClient\Enums\EnrollmentMetric;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Measure;

$total = PDE::district('124157203')->year('2024-2025')->financials()
    ->totalWithSources(Measure::Actual, FinancialCategory::Revenue);

$enrollment = PDE::district('124157203')->year('2024-2025')->enrollments()
    ->totalWithSources(EnrollmentMetric::Enrollment);

$amount = $account->amountWithSources(Measure::Budget);
$variance = $account->varianceWithSources();

$total->value;
$total->provenance->sourcesFor('value');
$total->toArray(); // ['value' => ..., 'provenance' => ...]
```

These methods return `Support\SourcedValue` and apply the same rounding, aggregation, and missing-data behavior as their numeric counterparts. `FundBalanceRecord::totalWithSources()` also returns its total with references to all three input classifications.

## Exported structure

`provenance.sources` is a deduplicated list. Each entry contains `id`, `label`, `url`, `page_url`, `period`, `sha256`, and `downloaded_at`. PHP uses camelCase for `pageUrl` and `downloadedAt`; JSON uses snake_case. Optional metadata can be null.

`provenance.field_sources` maps serialized field names to lists of source IDs. Each ID resolves to an entry in that result's `sources` list. Unrequested or unknown-source fields have empty lists. IDs distinguish the listing URL, file URL, and content fingerprint, allowing different downloaded revisions to remain distinct.

Record fields retain their existing names and shapes. References are stored alongside the data, including on nested account and grade records. Use normal Spatie `only()`/`except()` projections to choose exported fields; `only('provenance')` safely exports just citations. Public metadata objects also support Spatie projections. Internal cache serialization always preserves complete citations.

## Sources and revisions

The listing page comes from the actual configured finder, including discovery served from cache. File URLs are preserved as discovered rather than reconstructed from filenames. Worksheet names and cell coordinates are not tracked.

Downloaded workbooks have a `.pde-source.json` sidecar containing source details, the content hash, and the retrieval timestamp. The built-in raw downloader writes the same sidecar. Parsed queries verify cached bytes, enrich older verified manifests, and download once when a file lacks trustworthy source metadata or its content has changed. This works for local and remote Laravel disks.

PDE can update a workbook at the same URL. `sha256` identifies the cached bytes; `downloaded_at` records retrieval time, not PDE's publication date. A public URL may later serve a newer revision. Parsed caches include source metadata and use a new version namespace.

Bundled account labels and parent relationships cite the [2024-2025 PDE Chart of Accounts](https://www.pa.gov/content/dam/copapwp-pagov/en/education/documents/schools/grants-and-funding/school-finances/comptrollers-office/chart%20of%20accounts%202024-2025.pdf), linked from the [AFR detailed-data page](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/summary-of-annual-financial-report-data/afr-data-detailed). All 623 bundled labels and hierarchy entries were checked against that reference. Its period identifies the chart edition, independently of the queried fiscal year. Resource checksums prevent edited or custom chart files from inheriting this citation.

## Custom data and persistence

Constructors retain their existing arguments and append optional `provenance`. To cite manually constructed data, pass a `Support\DataProvenance` containing `SourceReference` objects and optional field-to-ID mappings. A registry without field mappings applies to the supplied data fields. Manually constructed records with no supplied provenance have no source references. Local filenames and temporary paths never become public citations.

All 13 optional migration templates include a nullable JSON `provenance` column. Preserve the entire metadata object, since one record can draw from multiple sources. With an Eloquent `array` cast, assign `$record->provenance->toArray()`; with the query builder, JSON-encode it explicitly. Existing consumer databases need an application migration to add the column. The package never migrates a consumer database automatically.

The dataset examples use explicitly labeled synthetic sources on `fixtures.example`; their values and URLs are test fixtures. The PDE source links at the top of each dataset page identify the real public reports.
