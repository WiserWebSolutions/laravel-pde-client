# Real estate tax rates

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/financial-data-elements)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->realEstateTaxRates();
$results = $query->get(); // Collection<RealEstateTaxRateRecord>
$result = $results->sole();
$value = $result->mills; // 15.5

$first = $query->first(); // RealEstateTaxRateRecord|null
$only = $query->sole(); // RealEstateTaxRateRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<RealEstateTaxRateRecord>, flat records
```

Rates are in mills: dollars per $1,000 of assessed value. A district spanning counties can have multiple records, so use get() and iterate; sole() requires exactly one county/notes record. taxRates() is an alias.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "school_year": "2024-2025",
    "county": "Fixture County",
    "notes": "Municipality A",
    "mills": 15.5,
    "community_college_mills": 0.2,
    "provenance": {
        "sources": [
            {
                "id": "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b",
                "label": "Synthetic tax-rates workbook",
                "url": "https://fixtures.example/tax-rates.xlsx",
                "page_url": "https://fixtures.example/reports/tax-rates",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "aun": [
                "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b"
            ],
            "district_name": [
                "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b"
            ],
            "school_year": [
                "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b"
            ],
            "county": [
                "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b"
            ],
            "notes": [
                "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b"
            ],
            "mills": [
                "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b"
            ],
            "community_college_mills": [
                "991f5fd438e7e8ce4315e6daea44fc7b32af238ec7510c035d8f45a48b9fd49b"
            ]
        }
    }
}
```

## Fields

Source citations are included in `provenance`. Use `sourcesFor()` to inspect an individual field; see [public source citations](../sources.md).

PHP uses camelCase; the serialized payload uses snake_case. Nullable fields retain a reason available through availability()/hasData().

- `aun` → `aun` (`string`): Nine-digit administrative unit identifier, stored as a string.
- `districtName` → `district_name` (`string|null`): Published district/LEA name.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `county` → `county` (`string|null`): Published taxing county; one district can have several county-specific rows.
- `notes` → `notes` (`string|null`): Published municipality or notes label.
- `mills` → `mills` (`float|null`): Published district millage; nullable for blank/unavailable cells.
- `communityCollegeMills` → `community_college_mills` (`float|null`): Published community-college millage, when included.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('mills')) {
    $value = $datum->mills;
}

try {
    $value = $datum?->mills;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->mills; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
