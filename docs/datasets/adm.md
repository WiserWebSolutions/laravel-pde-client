# Average Daily Membership

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/financial-data-elements)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->enrollments()->averageDailyMembership();
$results = $query->get(); // Collection<AdmRecord>
$result = $results->sole();
$value = $result->nonresidentAdm; // 2

$first = $query->first(); // AdmRecord|null
$only = $query->sole(); // AdmRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<AdmRecord>, flat records
```

ADM and WADM measure membership, not October enrollment headcounts. adjustmentFactor is a multiplier. breakdown preserves raw numeric column labels. adm() is an alias. Optional PDE-363 metrics are marked not_collected when their columns are absent; fixture years do not establish a universal historical cutoff.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "school_year": "2024-2025",
    "adm": 100,
    "wadm": 105,
    "adjusted_adm": 102,
    "nonresident_adm": 2,
    "total_adm_pde363": 98,
    "special_education_adm": 10,
    "adjustment_factor": 1.02,
    "breakdown": {
        "ADM Kindergarten": 20
    },
    "provenance": {
        "sources": [
            {
                "id": "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f",
                "label": "Synthetic adm workbook",
                "url": "https://fixtures.example/adm.xlsx",
                "page_url": "https://fixtures.example/reports/adm",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "aun": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "district_name": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "county": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "school_year": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "adm": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "wadm": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "adjusted_adm": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "nonresident_adm": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "total_adm_pde363": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "special_education_adm": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "adjustment_factor": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
            ],
            "breakdown": [
                "887861e58ef1ff372827d8938c14c852ae087f667835a8a554bedc8d7195d60f"
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
- `adm` → `adm` (`float|null`): Average Daily Membership.
- `wadm` → `wadm` (`float|null`): Weighted Average Daily Membership.
- `adjustedAdm` → `adjusted_adm` (`float|null`): Published adjusted ADM.
- `nonresidentAdm` → `nonresident_adm` (`float|null`): Published nonresident ADM; absent optional columns are not_collected.
- `totalAdmPde363` → `total_adm_pde363` (`float|null`): Published PDE-363 total ADM, when collected.
- `specialEducationAdm` → `special_education_adm` (`float|null`): Published special-education ADM, when collected.
- `adjustmentFactor` → `adjustment_factor` (`float|null`): Published ADM adjustment multiplier.
- `breakdown` → `breakdown` (`MetricMap|null`): MetricMap retaining raw ADM numeric column labels.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('nonresidentAdm')) {
    $value = $datum->nonresidentAdm;
}

try {
    $value = $datum?->nonresidentAdm;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->nonresidentAdm; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
