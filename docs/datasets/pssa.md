# PSSA assessments

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/data-and-reporting/assessment-reporting)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->assessments()->pssa()->subject('Math')->grade('3')->allStudents();
$results = $query->get(); // Collection<AssessmentRecord>
$result = $results->sole();
$value = $result->percentProficientOrAbove; // 70

$first = $query->first(); // AssessmentRecord|null
$only = $query->sole(); // AssessmentRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<AssessmentRecord>, flat records
```

Proficiency percentages are on the 0–100 scale. scored counts tested students. Supported filters are subject(), grade(), group(), and allStudents(). Total is a tested-grade aggregate, not an additional grade to sum with individual grades. Suppressed <11 and * cells remain null with a suppressed reason.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "school_year": "2024-2025",
    "exam": "pssa",
    "subject": "Math",
    "grade": "3",
    "group": "All Students",
    "scored": 100,
    "percent_advanced": 20,
    "percent_proficient": 50,
    "percent_basic": 20,
    "percent_below_basic": 10,
    "percent_proficient_or_above": 70
}
```

## Fields

PHP uses camelCase; the serialized payload uses snake_case. Nullable fields retain a reason available through availability()/hasData().

- `aun` → `aun` (`string`): Nine-digit administrative unit identifier, stored as a string.
- `districtName` → `district_name` (`string|null`): Published district/LEA name.
- `county` → `county` (`string|null`): Published county label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `exam` → `exam` (`Exam`): Exam enum: pssa or keystone.
- `subject` → `subject` (`string`): Published subject label.
- `grade` → `grade` (`string`): Canonical grade label; assessment Total is an aggregate.
- `group` → `group` (`string`): Published demographic label; All Students for assessments, Total for headline graduation.
- `scored` → `scored` (`float|null`): Number scored; suppressed populations remain null.
- `percentAdvanced` → `percent_advanced` (`float|null`): Percentage advanced, 0–100.
- `percentProficient` → `percent_proficient` (`float|null`): Percentage proficient, 0–100.
- `percentBasic` → `percent_basic` (`float|null`): Percentage basic, 0–100.
- `percentBelowBasic` → `percent_below_basic` (`float|null`): Percentage below basic, 0–100.
- `percentProficientOrAbove` → `percent_proficient_or_above` (`float|null`): Percentage proficient or above, 0–100.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('percentProficientOrAbove')) {
    $value = $datum->percentProficientOrAbove;
}

try {
    $value = $datum?->percentProficientOrAbove;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->percentProficientOrAbove; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
