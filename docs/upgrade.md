# Upgrading to the next major release

[Documentation index](index.md)

## Historical reads

The default now throws DataNotCollectedException for verified omitted report fields or requested demographics. To preserve nullable historical reads, publish/update configuration and set PDE_CLIENT_MISSING_DATA=return_null. Use availability()/hasData() to distinguish omissions, suppression, blanks, and unrequested data. Fetch new DTOs after changing policy.

Full Spatie exports enforce the same policy. Use only()/except() or opt into return_null before exporting old reports. Unknown names remain errors. Normalized caches use a new parser/source namespace, so old cached nulls are not assigned guessed meanings. Clear any consumer caches containing old serialized DTO objects when upgrading.

## Collections and imports

financials()->get() and enrollments()->get() always return collections. Replace direct property access on get() with first(), sole(), or get()->sole(). A financial/enrollment sole() result is a year summary; its accounts/grades collections contain flat DTOs. records() exposes a LazyCollection for importing those DTOs one year at a time.

## Explicit totals

Custom FinancialQuery construction no longer takes an unused Container argument; use Laravel container resolution for queries.

Financial total() requires Measure and FinancialCategory; FinancialRecord::amount() requires Measure. Enrollment total() requires EnrollmentMetric. EnrollmentMetric cases are Enrollment, Projections, and EnglishLearners. Financial totals avoid repeated rollups; separate dataset metrics are not added together. A required missing input produces null instead of a partial total. FundBalanceRecord::total() also requires all three components.

## Metric maps and identity

breakdown, categories, subCounts, projectedSubCounts, and englishLearnersSubCounts are immutable MetricMap objects. Array reads still work, but use toArray() for persistence and stop mutating these fields. Selection of literal nested keys is supported by only()/except().

The default district is unset. Configure or pass a nine-digit string AUN. Fiscal ranges must contain consecutive years. PendingQuery now supports the same recent/all/explicit year context as dataset queries. community() throws because it has no implemented endpoint.

## Sources and storage

Use Support\SpreadsheetReader for custom parsers; the former FinancialData\Parsing namespace remains a deprecated bridge. XLS and XLSX formulas require cached results; the package does not calculate formulas itself.

Downloaded workbooks expire, sources/content fingerprint parsed caches, and changed source URLs force a refresh. Failed streams/writes, HTTP failures, and malformed workbooks remain visible even with return_null. Retry behavior applies only to transient HTTP failures.

Review the optional migration templates in a consumer application: AUNs need nine characters, nullable metrics remain nullable, and column comments use Laravel method chaining. This package does not apply those migrations.

The package retains PHP 8.2 and Laravel 10–13 API compatibility and now requires Spatie Laravel Data 4.23 or newer within major 4 for its transformation adapter. Resolve dependencies for the target runtime; do not copy a development vendor directory into an older application.
