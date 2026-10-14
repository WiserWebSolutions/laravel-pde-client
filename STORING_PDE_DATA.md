# Storing PDE Data

[Current endpoint documentation](docs/index.md) · [Major-release upgrade guide](docs/upgrade.md)

This guide explains how to use the PDE Client package to fetch Pennsylvania Department of Education data and store it in your application database.

## Overview

The `laravel-pde-client` package provides fluent query builders to discover, download, and parse PDE data, but **does not include database tables or a service provider for storing the data**. This is intentional—different applications have different storage needs.

The `migrations/` directory contains **template migrations** you can use as a reference to design your own schema. These templates correspond directly to the data types the package provides via its Data classes.

## Quick Start

### 1. Copy Migrations to Your Application

Copy the migrations you need from `migrations/` to your Laravel application's `database/migrations/` directory:

```bash
# Copy all migrations
cp -r vendor/wiserwebsolutions/laravel-pde-client/migrations/* database/migrations/

# Or copy individual ones
cp vendor/wiserwebsolutions/laravel-pde-client/migrations/2024_01_01_000001_create_financial_records_table.php database/migrations/
```

### 2. Update Timestamps and Run Migrations

Laravel migrations require unique timestamp prefixes. Update them to match your migration sequence:

```bash
# Rename the copied migrations with current timestamps
# For example: 2024_12_06_120000_create_financial_records_table.php
```

Then run the migrations:

```bash
php artisan migrate
```

### 3. Fetch Data and Store It

Create a service/command to fetch PDE data and store it:

```php
use WiserWebSolutions\PDEClient\Facades\PDE;
use App\Models\FinancialRecord;

// Fetch financial data
$records = PDE::district('101260303')
    ->year('2024-2025')
    ->financials()
    ->budget()
    ->records();

// Store in your database
foreach ($records as $record) {
    FinancialRecord::create([
        'aun' => $record->aun,
        'district_name' => $record->districtName,
        'county' => $record->county,
        'fiscal_year' => $record->fiscalYear,
        'category' => $record->category->value,
        'account_code' => $record->accountCode,
        'account_name' => $record->accountName,
        'parent_code' => $record->parentCode,
        'budget' => $record->budget,
    ]);
}
```

When saving a complete snapshot, capture each public property's availability()->value in the template availability JSON column; for MetricMap values also capture each key's availability. Bare nulls cannot preserve historical reasons. Use updateOrCreate/upsert keyed by the migration's unique columns for repeatable imports. The create() examples below assume an initial import.

## Data Organization

The PDE Client organizes data into five categories with multiple datasets:

### 1. Financial Data (`->financials()`)

**Primary dataset:** Budget (GFB) and Actual (AFR) financial records

**Related datasets:**

- `->fundBalance()` — Fund balance classifications
- `->indebtedness()` — Statement of Indebtedness
- `->realEstateTaxRates()` — Real estate tax rates
- `->selectedData()` — Aid ratio, WADM, etc.
- `->actOneIndex()` — Act 1 adjusted index

**Migrations:**

- `create_financial_records_table`
- `create_fund_balance_records_table`
- `create_indebtedness_records_table`
- `create_real_estate_tax_rate_records_table`
- `create_selected_data_records_table`
- `create_act_one_index_records_table`

### 2. Enrollment Data (`->enrollments()`)

**Primary dataset:** General enrollment counts, enrollment projections, English learner counts

**Related datasets:**

- `->economicallyDisadvantaged()` — Low-income student counts
- `->averageDailyMembership()` — ADM/WADM

**Migrations:**

- `create_enrollment_records_table`
- `create_economically_disadvantaged_records_table`
- `create_adm_records_table`

### 3. Assessment Data (`->assessments()`)

**Primary dataset:** PSSA and Keystone proficiency results

**Related datasets:**

- `->graduation()` — Graduation rates and dropouts

**Migrations:**

- `create_assessment_records_table`
- `create_graduation_records_table`
- `create_dropout_records_table`

### 4. Personnel Data (`->personnel()`)

**Primary dataset:** Professional staff headcounts and salary/experience averages

**Migrations:**

- `create_personnel_records_table`

### 5. Community Data (`->community()`)

Unavailable: terminal calls throw PDEClientException.

## Example: Storing Enrollment Data

```php
use WiserWebSolutions\PDEClient\Facades\PDE;
use App\Models\EnrollmentRecord;

// Fetch enrollment with English learner data
$records = PDE::district('101260303')
    ->year('2024-2025')
    ->enrollments()
    ->withEnglishLearners()
    ->records();

foreach ($records as $record) {
    EnrollmentRecord::create([
        'aun' => $record->aun,
        'district_name' => $record->districtName,
        'county' => $record->county,
        'lea_type' => $record->leaType,
        'school_year' => $record->schoolYear,
        'grade' => $record->grade,
        'count' => $record->count,
        'sub_counts' => $record->subCounts?->toArray(),
        'english_learners_count' => $record->englishLearnersCount,
        'english_learners_sub_counts' => $record->englishLearnersSubCounts?->toArray(),
    ]);
}
```

## Example: Storing Assessment Data

```php
use WiserWebSolutions\PDEClient\Facades\PDE;
use App\Models\AssessmentRecord;

// Fetch PSSA results
$records = PDE::district('101260303')
    ->year('2024-2025')
    ->assessments()
    ->pssa()
    ->records();

foreach ($records as $record) {
    AssessmentRecord::create([
        'aun' => $record->aun,
        'district_name' => $record->districtName,
        'county' => $record->county,
        'school_year' => $record->schoolYear,
        'exam' => $record->exam->value,
        'subject' => $record->subject,
        'grade' => $record->grade,
        'group' => $record->group,
        'scored' => $record->scored,
        'percent_advanced' => $record->percentAdvanced,
        'percent_proficient' => $record->percentProficient,
        'percent_basic' => $record->percentBasic,
        'percent_below_basic' => $record->percentBelowBasic,
    ]);
}
```

## Handling JSON Data

Some tables store JSON to preserve variable data structures across years:

### Sub-Counts (Enrollment)

Enrollment records include `sub_counts` that break down enrollment by category (e.g., kindergarten AM/PM split):

```php
// Raw data from PDE
$enrollment->subCounts?->toArray(); // ['K5A' => 45, 'K5F' => 120]

// Stored as JSON in the database
$record->sub_counts; // '{"K5A": 45, "K5F": 120}'

// Query JSON data
EnrollmentRecord::where('sub_counts->K5A', '>', 40)->get();
```

### Category Breakdowns (Indebtedness, ADM)

These use JSON to store PDE's category labels and their corresponding amounts:

```php
// Indebtedness categories
$indebtedness->categories?->toArray(); // ['General Obligation Bonds' => 1000000, 'Notes Payable' => 500000]

// ADM breakdown
$adm->breakdown?->toArray(); // ['ADM Kindergarten HT5' => 45, 'ADM Grade 1' => 150, 'WADM Elementary' => 155]
```

### Selected data and Act 1 index

Selected data exposes named numeric properties such as $selectedData->aidRatio and $selectedData->equalizedMills. Act 1 exposes $actOneIndex->index as a fraction (0.041 = 4.1%). The repaired templates use those serialized field names rather than generic JSON blobs.

## Design Patterns

### Importing a District

Each query selects one district. Loop over an application-maintained list of AUNs for multiple districts; automatic district discovery is a future feature. This command imports the selected district:

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use WiserWebSolutions\PDEClient\Facades\PDE;
use App\Models\FinancialRecord;

class ImportPDEFinancials extends Command
{
    protected $signature = 'pde:import-financials {district} {--year=}';

    public function handle()
    {
        $query = PDE::district($this->argument('district'))->financials()->budget();

        if ($this->option('year')) {
            $query->year($this->option('year'));
        } else {
            $query->allYears();
        }

        $records = $query->records();
        $imported = 0;

        foreach ($records as $record) {
            $imported++;
            FinancialRecord::updateOrCreate(
                [
                    'aun' => $record->aun,
                    'fiscal_year' => $record->fiscalYear,
                    'category' => $record->category->value,
                    'account_code' => $record->accountCode,
                ],
                [
                    'district_name' => $record->districtName,
                    'county' => $record->county,
                    'account_name' => $record->accountName,
                    'parent_code' => $record->parentCode,
                    'budget' => $record->budget,
                ]
            );
        }

        $this->info("Imported {$imported} financial records");
    }
}
```

### Change Tracking

Use `updated_at` and `created_at` timestamps to track when data was last fetched:

```php
// Find stale data (older than 30 days)
$staleRecords = FinancialRecord::where('updated_at', '<', now()->subDays(30))->get();

// Only update records newer than a certain import
$recentRecords = AssessmentRecord::where('updated_at', '>', $lastImportDate)->get();
```

### Data Validation

Use Laravel's validation to ensure data integrity:

```php
use App\Models\EnrollmentRecord;

$validated = validator($data, [
    'aun' => ['required', 'string', 'regex:/^\d{9}$/'],
    'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
    'grade' => 'required|in:PK,K,1,2,3,4,5,6,7,8,9,10,11,12',
    'count' => 'nullable|numeric|min:0',
])->validate();

// Also validate consecutive years with FiscalYear::parse($validated['school_year']).
EnrollmentRecord::create($validated);
```

## Key Fields

### Identifiers

- **aun** — Administrative Unit Number (9 digits, unique LEA identifier)
- **school_year** / **fiscal_year** — Format: `YYYY-YYYY` (e.g., `2024-2025`)
- **category** — Data type (e.g., `revenue`, `expenditure`, `pssa`, `keystone`)

### Numeric Precision

- **Financial amounts** — `decimal(18, 4)` (dollars with cents)
- **Percentages** — `decimal(18, 4)` (0-100 range)
- **Rates/Fractions** — `decimal(18, 6)` (0-1 range)

### Nullable Values

DTO reads and exports throw for verified uncollected fields by default. Configure return_null before building DTOs if the importer should preserve nulls, and store availability reasons separately. See [missing data](docs/missing-data.md). Model JSON casts should use array for metric maps and the optional availability column.

Many fields are nullable because:

- PDE suppresses data for groups under 11 students
- Some columns are only available in certain school years
- A dataset might not have been requested in a query

## Troubleshooting

### "No file found" errors

The PDE Client tries to find data files on PDE's website. If no files are found:

1. Check that the year exists for that dataset
2. Verify the district's AUN is correct
3. Some datasets may not publish every year
4. See the `config/pde-client.php` for page URLs and customize them if needed

### Null values in results

Null values usually mean:

- PDE suppressed the data (too small population)
- The school year doesn't have that data published
- The dataset wasn't requested in the query
- Check the Data class docblock to understand each field

### Performance with large datasets

When importing data for many districts/years:

1. Use `->allYears()` instead of looping `->year()`
2. Use batch operations: `Model::insert($batchOfRecords)`
3. Consider using `updateOrCreate()` to avoid duplicates
4. Disable foreign key constraints during bulk import if needed

## Next Steps

- Review the migrations in `migrations/` for your specific needs
- Create Eloquent models corresponding to the tables
- Build commands/jobs to fetch and store data on a schedule
- Set up monitoring/alerts for import failures
- Consider caching frequently-queried data
