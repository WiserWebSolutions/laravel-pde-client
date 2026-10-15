# Act 1 index

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/property-tax-relief/act-1-index)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->actOneIndex();
$results = $query->get(); // Collection<ActOneIndexRecord>
$result = $results->sole();
$value = $result->index; // 0.041

$first = $query->first(); // ActOneIndexRecord|null
$only = $query->sole(); // ActOneIndexRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<ActOneIndexRecord>, flat records
```

The adjusted index is a fraction: 0.041 represents 4.1%. PDE updates one history workbook in place. This value is the published index, not a computed tax recommendation.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "school_year": "2024-2025",
    "index": 0.041,
    "provenance": {
        "sources": [
            {
                "id": "e727e1d951426edad80961307558877003d9e05a99b042e67c91a721d3f2ca83",
                "label": "Synthetic act-one-index workbook",
                "url": "https://fixtures.example/act-one-index.xlsx",
                "page_url": "https://fixtures.example/reports/act-one-index",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "aun": [
                "e727e1d951426edad80961307558877003d9e05a99b042e67c91a721d3f2ca83"
            ],
            "district_name": [
                "e727e1d951426edad80961307558877003d9e05a99b042e67c91a721d3f2ca83"
            ],
            "county": [
                "e727e1d951426edad80961307558877003d9e05a99b042e67c91a721d3f2ca83"
            ],
            "school_year": [
                "e727e1d951426edad80961307558877003d9e05a99b042e67c91a721d3f2ca83"
            ],
            "index": [
                "e727e1d951426edad80961307558877003d9e05a99b042e67c91a721d3f2ca83"
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
- `county` → `county` (`string|null`): Published county label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `index` → `index` (`float|null`): Adjusted Act 1 index as a fraction, for example 0.041.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('index')) {
    $value = $datum->index;
}

try {
    $value = $datum?->index;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->index; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
