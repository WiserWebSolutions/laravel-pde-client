# Indebtedness

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/summary-of-annual-financial-report-data/afr-data-detailed)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->indebtedness()->fundType('governmental')->phase('end');
$results = $query->get(); // Collection<IndebtednessRecord>
$result = $results->sole();
$value = $result->total; // 1000

$first = $query->first(); // IndebtednessRecord|null
$only = $query->sole(); // IndebtednessRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<IndebtednessRecord>, flat records
```

Debt is reported in USD. fundType() accepts all, governmental, or proprietary; phase() accepts beginning, additional, retirements, or end. All-fund totals require both fund types. categories is an immutable MetricMap.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "fiscal_year": "2024-2025",
    "fund_type": "governmental",
    "phase": "end",
    "total": 1000,
    "categories": {
        "Bonds": 1000
    }
}
```

## Fields

PHP uses camelCase; the serialized payload uses snake_case. Nullable fields retain a reason available through availability()/hasData().

- `aun` → `aun` (`string`): Nine-digit administrative unit identifier, stored as a string.
- `districtName` → `district_name` (`string|null`): Published district/LEA name.
- `county` → `county` (`string|null`): Published county label.
- `fiscalYear` → `fiscal_year` (`string`): Canonical fiscal year, YYYY-YYYY.
- `fundType` → `fund_type` (`FundType`): FundType enum: all, governmental, or proprietary.
- `phase` → `phase` (`DebtPhase`): DebtPhase enum: beginning, additional, retirements, or end.
- `total` → `total` (`float|null`): Complete USD total for the selected debt group.
- `categories` → `categories` (`MetricMap|null`): MetricMap of source debt-category labels to nullable USD amounts.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('total')) {
    $value = $datum->total;
}

try {
    $value = $datum?->total;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->total; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
