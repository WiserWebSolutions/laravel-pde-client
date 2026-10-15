# English learners

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/data-and-reporting/enrollment)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->enrollments()->onlyEnglishLearners()->grade('1');
$results = $query->get(); // Collection<EnrollmentYearSummary>
$result = $results->sole();
$value = $result->grades->sole()->englishLearnersCount; // 20

$first = $query->first(); // EnrollmentYearSummary|null
$only = $query->sole(); // EnrollmentYearSummary; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<EnrollmentRecord>, flat records
```

English learner counts are a subset of students. onlyEnglishLearners() selects this dataset; withEnglishLearners() joins it with public enrollment. Never add the EL subset to general enrollment. PDE publishes these by school and grade; the package combines school rows by AUN.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "lea_type": "SD",
    "school_year": "2024-2025",
    "enrollment_total": null,
    "projected_enrollment_total": null,
    "english_learners_total": 20,
    "economically_disadvantaged_total": null,
    "economically_disadvantaged": null,
    "grades": [
        {
            "aun": "124157203",
            "district_name": "Fixture District",
            "county": "Fixture County",
            "lea_type": "SD",
            "school_year": "2024-2025",
            "grade": "1",
            "count": null,
            "sub_counts": [],
            "projected_count": null,
            "projected_sub_counts": [],
            "english_learners_count": 20,
            "english_learners_sub_counts": {
                "1": 20
            },
            "provenance": {
                "sources": [
                    {
                        "id": "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea",
                        "label": "Synthetic english-learners workbook",
                        "url": "https://fixtures.example/english-learners.xlsx",
                        "page_url": "https://fixtures.example/reports/english-learners",
                        "period": "2024-2025",
                        "sha256": null,
                        "downloaded_at": null
                    }
                ],
                "field_sources": {
                    "aun": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "district_name": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "county": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "lea_type": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "school_year": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "grade": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "english_learners_count": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "english_learners_sub_counts": [
                        "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
                    ],
                    "count": [],
                    "sub_counts": [],
                    "projected_count": [],
                    "projected_sub_counts": []
                }
            }
        }
    ],
    "provenance": {
        "sources": [
            {
                "id": "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea",
                "label": "Synthetic english-learners workbook",
                "url": "https://fixtures.example/english-learners.xlsx",
                "page_url": "https://fixtures.example/reports/english-learners",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "enrollment_total": [],
            "projected_enrollment_total": [],
            "english_learners_total": [
                "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
            ],
            "economically_disadvantaged_total": [],
            "aun": [
                "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
            ],
            "district_name": [
                "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
            ],
            "county": [
                "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
            ],
            "lea_type": [
                "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
            ],
            "school_year": [
                "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
            ],
            "grades": [
                "5411d22084a699e60b6624a0f7ebeac6337051daeb7838ecd85a266ba3cb60ea"
            ],
            "economically_disadvantaged": []
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
- `leaType` → `lea_type` (`string|null`): Published LEA type label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `enrollmentTotal` → `enrollment_total` (`float|null`): Sum of the public-enrollment grades reported by the selected dataset.
- `projectedEnrollmentTotal` → `projected_enrollment_total` (`float|null`): Separate sum of selected projection grades.
- `englishLearnersTotal` → `english_learners_total` (`float|null`): Separate sum of reported English learner grades; this is a subset.
- `economicallyDisadvantagedTotal` → `economically_disadvantaged_total` (`float|null`): Nested district-level low-income count, when requested.
- `economicallyDisadvantaged` → `economically_disadvantaged` (`EconomicallyDisadvantagedRecord|null`): Optional nested EconomicallyDisadvantagedRecord.
- `grades` → `grades` (`Collection`): Collection of EnrollmentRecord objects for drill-down.

Nested `EnrollmentRecord` fields:

- `aun` → `aun`: Nine-digit administrative unit identifier, stored as a string.
- `districtName` → `district_name`: Published district/LEA name.
- `county` → `county`: Published county; a tax-rate record can represent one of several counties.
- `leaType` → `lea_type`: Published LEA type label.
- `schoolYear` → `school_year`: Canonical school year, YYYY-YYYY.
- `grade` → `grade`: Canonical grade label; assessment Total is an aggregate.
- `count` → `count`: Published enrollment count.
- `subCounts` → `sub_counts`: MetricMap of raw public-enrollment grade variants.
- `projectedCount` → `projected_count`: Separate projected enrollment count.
- `projectedSubCounts` → `projected_sub_counts`: MetricMap of raw projection grade variants.
- `englishLearnersCount` → `english_learners_count`: Separate English learner count.
- `englishLearnersSubCounts` → `english_learners_sub_counts`: MetricMap of raw English learner grade variants.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('englishLearnersCount')) {
    $value = $datum->englishLearnersCount;
}

try {
    $value = $datum?->englishLearnersCount;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->englishLearnersCount; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
