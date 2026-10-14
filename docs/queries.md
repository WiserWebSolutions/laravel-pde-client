# Queries and year selection

[Documentation index](index.md)

Start with PDE::query() or PDE::district(). Select a category and then an endpoint. Context applies before or after choosing the endpoint and flows into sibling queries.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->budget()->revenues();
$summaries = $query->get(); // always a Laravel Collection
$first = $query->first(); // DTO or null
$only = $query->sole(); // DTO; requires exactly one matching result

$records = $query->records(); // LazyCollection<FinancialRecord>
$records->chunk(100)->each(function ($chunk) {
    foreach ($chunk as $record) {
        // Import a flat account row.
    }
});
```

get() and ordinary iteration return year summaries for financials()/enrollments(); their records() methods return flat account/grade DTOs. Other endpoints return record DTOs. A sole financial summary can contain many accounts; select one with account() or use its accounts collection.

Omitting year() selects the newest available year. year(), year('recent'), and resetting a prior selection do the same. allYears(), years(), and year('all') select chronological history. year(2024), year('2024-25'), and year('2024-2025') select the same year. Nonconsecutive ranges such as 2024-2030 are rejected. Assessment years follow the school-year convention: 2024-2025 denotes the spring-2025 testing window.

Explicit years skip unrelated year discovery. Queries download only the workbook families needed by the selected dataset/category. Flat record streams load a year at a time; each normalized year table can still contain all districts from that workbook.

## Totals

Select the measure explicitly; enrollment, forecasts, and EL subsets are different quantities, as are financial budget/actual and revenue/expenditure categories.

```php
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\EnrollmentMetric;

$revenues = PDE::district('124157203')->year(2024)->financials()
    ->total(Measure::Budget, FinancialCategory::Revenue);
$enrollment = PDE::district('124157203')->year(2024)->enrollments()
    ->total(EnrollmentMetric::Enrollment);
$learners = PDE::district('124157203')->year(2024)->enrollments()
    ->total(EnrollmentMetric::EnglishLearners);
```

total() uses its explicit measure arguments and retains district, year, account/grade filters. Financial totals count disjoint selected accounts, so parents and descendants are not counted twice. Required missing inputs yield null or the strict historical error. Financial rollups use reported nonzero parent amounts when present; otherwise they sum published child values. A known blank/suppressed child makes a derived total unavailable.

A missing demographic record is not replaced with fabricated metrics. first() returns null for a normal empty match; sole() always throws on zero or multiple matches. Unsupported community queries and operational failures throw.
