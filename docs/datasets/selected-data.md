# Selected data

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/financial-data-elements)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->selectedData();
$results = $query->get(); // Collection<SelectedDataRecord>
$result = $results->sole();
$value = $result->aidRatio; // 0.5

$first = $query->first(); // SelectedDataRecord|null
$only = $query->sole(); // SelectedDataRecord; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<SelectedDataRecord>, flat records
```

Aid ratio is a fraction; WADM/ADM are membership measures; equalized mills are millage; population density is residents per square mile. Instruction expense per WADM and total expenditure per ADM are published USD per membership unit, not ratios recomputed by this package. Rank fields are published positions. The aid-ratio reference year can differ from the expense year.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "school_year": "2024-2025",
    "aid_ratio": 0.5,
    "aid_ratio_rank": 100,
    "wadm": 105,
    "adm": 100,
    "adm_rank": 200,
    "equalized_mills": 15,
    "equalized_mills_rank": 150,
    "population_per_square_mile": 500,
    "population_per_square_mile_rank": 50,
    "instruction_expense_per_wadm": 9000,
    "instruction_expense_per_wadm_rank": 300,
    "total_expenditure_per_adm": 12000,
    "total_expenditure_per_adm_rank": 250
}
```

## Fields

PHP uses camelCase; the serialized payload uses snake_case. Nullable fields retain a reason available through availability()/hasData().

- `aun` → `aun` (`string`): Nine-digit administrative unit identifier, stored as a string.
- `districtName` → `district_name` (`string|null`): Published district/LEA name.
- `county` → `county` (`string|null`): Published county label.
- `schoolYear` → `school_year` (`string`): Canonical school year, YYYY-YYYY.
- `aidRatio` → `aid_ratio` (`float|null`): Published aid-ratio fraction.
- `aidRatioRank` → `aid_ratio_rank` (`float|null`): Published rank for aidRatio.
- `wadm` → `wadm` (`float|null`): Weighted Average Daily Membership.
- `adm` → `adm` (`float|null`): Average Daily Membership.
- `admRank` → `adm_rank` (`float|null`): Published rank for adm.
- `equalizedMills` → `equalized_mills` (`float|null`): Published equalized millage.
- `equalizedMillsRank` → `equalized_mills_rank` (`float|null`): Published rank for equalizedMills.
- `populationPerSquareMile` → `population_per_square_mile` (`float|null`): Published residents per square mile.
- `populationPerSquareMileRank` → `population_per_square_mile_rank` (`float|null`): Published rank for populationPerSquareMile.
- `instructionExpensePerWadm` → `instruction_expense_per_wadm` (`float|null`): Published actual instruction expense in USD per WADM.
- `instructionExpensePerWadmRank` → `instruction_expense_per_wadm_rank` (`float|null`): Published rank for instructionExpensePerWadm.
- `totalExpenditurePerAdm` → `total_expenditure_per_adm` (`float|null`): Published total expenditure in USD per ADM.
- `totalExpenditurePerAdmRank` → `total_expenditure_per_adm_rank` (`float|null`): Published rank for totalExpenditurePerAdm.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('aidRatio')) {
    $value = $datum->aidRatio;
}

try {
    $value = $datum?->aidRatio;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->aidRatio; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
