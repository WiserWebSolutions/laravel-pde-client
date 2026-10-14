# Cohort graduation

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/data-and-reporting/high-school-graduation)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->assessments()->graduation()->cohortYears(4)->group('Total');
$results = $query->get(); // Collection<GraduationRecord>
$result = $results->sole();
$value = $result->rate; // 0.9

$first = $query->first(); // GraduationRecord|null
$only = $query->sole(); // GraduationRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<GraduationRecord>, flat records
```

rate is a 0–1 fraction: 0.9 represents 90%. cohortYears() accepts 4, 5, or 6. group() is case insensitive. graduates and cohortSize are published for Total only; demographic counts are not_applicable. A recognized wide report header provides evidence of which demographic rate columns exist.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "lea_name": "Fixture District",
    "lea_type": "SD",
    "school_year": "2024-2025",
    "cohort_years": 4,
    "group": "Total",
    "graduates": 90,
    "cohort_size": 100,
    "rate": 0.9
}
```

## Fields

PHP uses camelCase; the serialized payload uses snake_case. Nullable fields retain a reason available through availability()/hasData().

- `aun` → `aun` (`string`): Nine-digit administrative unit identifier, stored as a string.
- `leaName` → `lea_name` (`string|null`): Published LEA name.
- `leaType` → `lea_type` (`string|null`): Published LEA type label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `cohortYears` → `cohort_years` (`CohortSpan`): CohortSpan enum serialized as 4, 5, or 6.
- `group` → `group` (`string`): Published demographic label; All Students for assessments, Total for headline graduation.
- `graduates` → `graduates` (`float|null`): Total-group graduates; not_applicable for demographic rows.
- `cohortSize` → `cohort_size` (`float|null`): Total-group cohort size; not_applicable for demographic rows.
- `rate` → `rate` (`float|null`): Published/computed rate as a fraction, 0–1.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('rate')) {
    $value = $datum->rate;
}

try {
    $value = $datum?->rate;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->rate; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
