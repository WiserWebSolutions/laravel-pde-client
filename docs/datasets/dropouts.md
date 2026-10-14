# Dropouts

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/data-and-reporting/high-school-graduation)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->assessments()->graduation()->dropouts();
$results = $query->get(); // Collection<DropoutRecord>
$result = $results->sole();
$value = $result->rate; // 0.03

$first = $query->first(); // DropoutRecord|null
$only = $query->sole(); // DropoutRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<DropoutRecord>, flat records
```

Counts represent students leaving during one school year, not cohort completion. rate is a 0–1 fraction (0.03 = 3%). Older per-school rows are combined by AUN, with synthetic total rows excluded. A missing school component makes the relevant district total null; rates require complete counts and a positive enrollment denominator. group() is for cohort mode.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "lea_name": "Fixture District",
    "county": "Fixture County",
    "school_year": "2024-2025",
    "enrollment": 100,
    "male_dropouts": 2,
    "female_dropouts": 1,
    "dropouts": 3,
    "rate": 0.03
}
```

## Fields

PHP uses camelCase; the serialized payload uses snake_case. Nullable fields retain a reason available through availability()/hasData().

- `aun` → `aun` (`string`): Nine-digit administrative unit identifier, stored as a string.
- `leaName` → `lea_name` (`string|null`): Published LEA name.
- `county` → `county` (`string|null`): Published county label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `enrollment` → `enrollment` (`float|null`): Published enrollment denominator used by this report.
- `maleDropouts` → `male_dropouts` (`float|null`): Published male dropout count.
- `femaleDropouts` → `female_dropouts` (`float|null`): Published female dropout count.
- `dropouts` → `dropouts` (`float|null`): Published complete dropout count.
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
