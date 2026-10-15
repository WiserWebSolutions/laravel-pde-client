# Economically disadvantaged enrollment

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/data-and-reporting/enrollment)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->enrollments()->economicallyDisadvantaged();
$results = $query->get(); // Collection<EconomicallyDisadvantagedRecord>
$result = $results->sole();
$value = $result->economicallyDisadvantagedCount; // 40

$first = $query->first(); // EconomicallyDisadvantagedRecord|null
$only = $query->sole(); // EconomicallyDisadvantagedRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<EconomicallyDisadvantagedRecord>, flat records
```

The published low-income count and enrollment denominator are district-level figures. percentEconomicallyDisadvantaged carries the published percentage (the fixture uses 40 for 40%). There is no grade breakdown. withEconomicallyDisadvantaged() nests this record in an enrollment summary.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "lea_type": "SD",
    "county": "Fixture County",
    "school_year": "2024-2025",
    "economically_disadvantaged_count": 40,
    "enrollment": 100,
    "percent_economically_disadvantaged": 40,
    "provenance": {
        "sources": [
            {
                "id": "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7",
                "label": "Synthetic economically-disadvantaged workbook",
                "url": "https://fixtures.example/economically-disadvantaged.xlsx",
                "page_url": "https://fixtures.example/reports/economically-disadvantaged",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "aun": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
            ],
            "district_name": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
            ],
            "lea_type": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
            ],
            "county": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
            ],
            "school_year": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
            ],
            "economically_disadvantaged_count": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
            ],
            "enrollment": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
            ],
            "percent_economically_disadvantaged": [
                "4e7f7a53d9068aa0ee28a50dbbdec06bdcaa9fcb0699b656ef3d5fa37332bee7"
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
- `leaType` → `lea_type` (`string|null`): Published LEA type label.
- `county` → `county` (`string|null`): Published county label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `economicallyDisadvantagedCount` → `economically_disadvantaged_count` (`float|null`): Published low-income student count.
- `enrollment` → `enrollment` (`float|null`): Published enrollment denominator used by this report.
- `percentEconomicallyDisadvantaged` → `percent_economically_disadvantaged` (`float|null`): Published percentage; fixture value 40 means 40%.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('economicallyDisadvantagedCount')) {
    $value = $datum->economicallyDisadvantagedCount;
}

try {
    $value = $datum?->economicallyDisadvantagedCount;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->economicallyDisadvantagedCount; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
