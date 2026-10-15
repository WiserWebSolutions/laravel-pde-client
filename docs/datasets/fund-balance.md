# Fund balance

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/summary-of-annual-financial-report-data/afr-data-detailed)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->fundBalance();
$results = $query->get(); // Collection<FundBalanceRecord>
$result = $results->sole();
$value = $result->committed; // 1000

$first = $query->first(); // FundBalanceRecord|null
$only = $query->sole(); // FundBalanceRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<FundBalanceRecord>, flat records
```

Year-end committed, assigned, and unassigned general fund balances are USD amounts. total() returns null when any required component is missing.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "fiscal_year": "2024-2025",
    "committed": 1000,
    "assigned": 2000,
    "unassigned": 3000,
    "provenance": {
        "sources": [
            {
                "id": "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1",
                "label": "Synthetic fund-balance workbook",
                "url": "https://fixtures.example/fund-balance.xlsx",
                "page_url": "https://fixtures.example/reports/fund-balance",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "aun": [
                "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1"
            ],
            "district_name": [
                "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1"
            ],
            "county": [
                "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1"
            ],
            "fiscal_year": [
                "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1"
            ],
            "committed": [
                "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1"
            ],
            "assigned": [
                "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1"
            ],
            "unassigned": [
                "7a4388b4f49f1c8680b41773e047c2df47a6d510889b661dad1ca0ba0d5c8ef1"
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
- `fiscalYear` → `fiscal_year` (`string`): Canonical fiscal year, YYYY-YYYY.
- `committed` → `committed` (`float|null`): Committed general fund balance, USD.
- `assigned` → `assigned` (`float|null`): Assigned general fund balance, USD.
- `unassigned` → `unassigned` (`float|null`): Unassigned general fund balance, USD.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('committed')) {
    $value = $datum->committed;
}

try {
    $value = $datum?->committed;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->committed; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
