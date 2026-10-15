# Professional personnel

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/data-and-reporting/school-staff/professional-and-support-personnel)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->personnel()->classroomTeachers();
$results = $query->get(); // Collection<PersonnelRecord>
$result = $results->sole();
$value = $result->averageSalary; // 65000

$first = $query->first(); // PersonnelRecord|null
$only = $query->sole(); // PersonnelRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<PersonnelRecord>, flat records
```

Counts are full-time headcounts. averageSalary is published USD salary; service and LEA tenure are years. Education level retains the source’s numeric coding. category() accepts professional, administrator, classroom_teacher, coordinator, or other. classroomTeachers()/administrators() are shortcuts. Professional includes the other categories, so do not sum all five. Omitted average columns are not_collected.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "lea_name": "Fixture District",
    "lea_type": "SD",
    "county": "Fixture County",
    "school_year": "2024-2025",
    "category": "classroom_teacher",
    "count": 10,
    "female_count": 6,
    "male_count": 4,
    "average_salary": 65000,
    "average_years_service": 12,
    "average_years_in_lea": 8,
    "average_education_level": 3,
    "provenance": {
        "sources": [
            {
                "id": "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f",
                "label": "Synthetic professional-personnel workbook",
                "url": "https://fixtures.example/professional-personnel.xlsx",
                "page_url": "https://fixtures.example/reports/professional-personnel",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "aun": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "lea_name": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "lea_type": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "county": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "school_year": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "category": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "count": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "female_count": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "male_count": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "average_salary": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "average_years_service": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "average_years_in_lea": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ],
            "average_education_level": [
                "3b798215a0ba6f5181769e0c4d2c273b906021122bc5de9b508cb19a1230060f"
            ]
        }
    }
}
```

## Fields

Source citations are included in `provenance`. Use `sourcesFor()` to inspect an individual field; see [public source citations](../sources.md).

PHP uses camelCase; the serialized payload uses snake_case. Nullable fields retain a reason available through availability()/hasData().

- `aun` → `aun` (`string`): Nine-digit administrative unit identifier, stored as a string.
- `leaName` → `lea_name` (`string|null`): Published LEA name.
- `leaType` → `lea_type` (`string|null`): Published LEA type label.
- `county` → `county` (`string|null`): Published county label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `category` → `category` (`PersonnelCategory`): PersonnelCategory enum; JSON contains one of professional, administrator, classroom_teacher, coordinator, or other.
- `count` → `count` (`float|null`): Published full-time professional-staff headcount.
- `femaleCount` → `female_count` (`float|null`): Published female professional-staff count.
- `maleCount` → `male_count` (`float|null`): Published male professional-staff count.
- `averageSalary` → `average_salary` (`float|null`): Published average salary, USD.
- `averageYearsService` → `average_years_service` (`float|null`): Published average service tenure, years.
- `averageYearsInLea` → `average_years_in_lea` (`float|null`): Published average tenure in the LEA, years.
- `averageEducationLevel` → `average_education_level` (`float|null`): Published numeric education-level coding; retain the source scale.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('averageSalary')) {
    $value = $datum->averageSalary;
}

try {
    $value = $datum?->averageSalary;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->averageSalary; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
