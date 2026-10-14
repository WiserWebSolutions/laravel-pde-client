# Template Migrations - Quick Reference

This file provides a quick reference for all available template migrations in the `migrations/` directory.

## What Are These?

Template migrations are database table definitions provided **as references** for storing PDE data in your application. They are **not included** in the service provider—you must copy and run them in your own Laravel application.

Each migration corresponds to a Data class that the PDE Client can parse and return from its fluent queries.

## Quick Lookup by Dataset

### Financial Data (5 tables)

| Migration | Purpose | Related Class | Data Source |
|-----------|---------|---------------|-------------|
| `2024_01_01_000001_create_financial_records_table.php` | Budget (GFB) and Actual (AFR) financial records | `FinancialRecord` | GFB/AFR |
| `2024_01_01_000002_create_indebtedness_records_table.php` | Statement of Indebtedness | `IndebtednessRecord` | AFR |
| `2024_01_01_000003_create_fund_balance_records_table.php` | Fund balance by classification | `FundBalanceRecord` | AFR |
| `2024_01_01_000011_create_real_estate_tax_rate_records_table.php` | Real estate tax rates | `RealEstateTaxRateRecord` | Financial Data Elements |
| `2024_01_01_000012_create_selected_data_records_table.php` | Miscellaneous financial data | `SelectedDataRecord` | Financial Data Elements |

### Enrollment Data (3 tables)

| Migration | Purpose | Related Class | Data Source |
|-----------|---------|---------------|-------------|
| `2024_01_01_000007_create_enrollment_records_table.php` | Enrollment counts by grade | `EnrollmentRecord` | Enrollment |
| `2024_01_01_000008_create_economically_disadvantaged_records_table.php` | Economically disadvantaged counts | `EconomicallyDisadvantagedRecord` | Enrollment |
| `2024_01_01_000010_create_adm_records_table.php` | Average Daily Membership (ADM) | `AdmRecord` | Financial Data Elements |

### Assessment Data (3 tables)

| Migration | Purpose | Related Class | Data Source |
|-----------|---------|---------------|-------------|
| `2024_01_01_000004_create_assessment_records_table.php` | PSSA & Keystone proficiency results | `AssessmentRecord` | Assessment |
| `2024_01_01_000005_create_graduation_records_table.php` | Cohort graduation rates | `GraduationRecord` | Assessment |
| `2024_01_01_000006_create_dropout_records_table.php` | Dropout rates | `DropoutRecord` | Assessment |

### Personnel Data (1 table)

| Migration | Purpose | Related Class | Data Source |
|-----------|---------|---------------|-------------|
| `2024_01_01_000009_create_personnel_records_table.php` | Professional staff data | `PersonnelRecord` | Personnel |

### Other Data (1 table)

| Migration | Purpose | Related Class | Data Source |
|-----------|---------|---------------|-------------|
| `2024_01_01_000013_create_act_one_index_records_table.php` | Act One Index values | `ActOneIndexRecord` | Financial Data Elements |

## Total: 13 Tables

## How to Use

1. **Identify which datasets you need** — review the "Quick Lookup" table above
2. **Copy migrations to your app** — `cp migrations/* database/migrations/`
3. **Update timestamps** — Laravel requires unique timestamps
4. **Run migrations** — `php artisan migrate`
5. **Create Eloquent models** — to match each table
6. **Build import commands** — to fetch and store PDE data (see `STORING_PDE_DATA.md`)

[Endpoint field definitions](docs/index.md) · [Strict/null export policy](docs/missing-data.md)

The templates are tested against all 17 documented flat-record examples using an isolated SQLite database. Each adds an optional availability JSON column that a consumer importer must populate explicitly. A nullable database column alone does not preserve the reason a value is missing.

## Migration Details

Each migration includes:

- **Clear table name** — maps to the Data class (e.g., `FinancialRecord` → `financial_records`)
- **DTO field names** — columns match each flat record's snake_case export
- **Appropriate data types** — `decimal` for money, `json` for flexible data
- **Nullable columns** — for data that PDE suppresses or varies by year
- **Indexes** — on commonly-filtered columns for query performance
- **Unique constraints** — to prevent duplicate data ingestion

## Common Patterns

### Identification (All Tables)

- `aun` — 9-digit LEA identifier
- `school_year` / `fiscal_year` — Format: `YYYY-YYYY` (e.g., `2024-2025`)
- `district_name`, `county` — Reference data (nullable)

### Timestamps (All Tables)

- `created_at`, `updated_at` — Track when data was fetched/updated

### Unique Constraints
Most tables have unique constraints on (aun, year, [other identifiers]) to ensure:

- One record per district per year per entity
- Prevention of duplicate data on re-import
- Data integrity when refreshing

### JSON Columns
Used for flexible storage when data structure varies:

- Sub-counts (enrollment grade splits)
- Category breakdowns (debt categories, ADM categories)
- Availability reasons (property and metric states); selected data and Act 1 use named columns

Query JSON in Laravel:
```php
// Filter where JSON field contains a specific value
Model::whereJsonContains('sub_counts->category', 'value')->get();

// Filter where the map has more than five keys
Model::whereJsonLength('sub_counts', '>', 5)->get();
```

## Data Precision

Follow these conventions for consistency:

| Type | Format | Example |
|------|--------|---------|
| Dollar amounts | `decimal(18, 4)` | Budget: 1,234,567.89 |
| Percentages | `decimal(18, 4)` | 95.50 (representing 95.50%) |
| Rates/Fractions | `decimal(18, 6)` | 0.9550 (representing 95.50% as a fraction) |
| Counts | `decimal` or `decimal(18, 4)` | 250 or 250.50 (for ADM) |

## Documentation

- **`migrations/README.md`** — Detailed explanation of each migration
- **`STORING_PDE_DATA.md`** — Full guide to fetching and storing PDE data
- **`migrations/` directory** — Individual migration files with inline comments

## Example: Complete Flow

```php
// 1. Query PDE data
use WiserWebSolutions\PDEClient\Facades\PDE;

$financials = PDE::district('101260303')
    ->year('2024-2025')
    ->financials()
    ->budget()
    ->records();

// 2. Transform to database format
$records = $financials->map(function($record) {
    return [
        'aun' => $record->aun,
        'district_name' => $record->districtName,
        'fiscal_year' => $record->fiscalYear,
        'category' => $record->category->value,
        'account_code' => $record->accountCode,
        'account_name' => $record->accountName,
        'parent_code' => $record->parentCode,
        'budget' => $record->budget,
        'actual' => $record->actual,
        'created_at' => now(),
        'updated_at' => now(),
    ];
});

// 3. Store in database
use App\Models\FinancialRecord;
$records->chunk(500)->each(fn ($batch) => FinancialRecord::insert($batch->all()));
```

## Key Files

```
migrations/
├── README.md                                       # Detailed migration documentation
├── 2024_01_01_000001_create_financial_records_table.php
├── 2024_01_01_000002_create_indebtedness_records_table.php
├── 2024_01_01_000003_create_fund_balance_records_table.php
├── 2024_01_01_000004_create_assessment_records_table.php
├── 2024_01_01_000005_create_graduation_records_table.php
├── 2024_01_01_000006_create_dropout_records_table.php
├── 2024_01_01_000007_create_enrollment_records_table.php
├── 2024_01_01_000008_create_economically_disadvantaged_records_table.php
├── 2024_01_01_000009_create_personnel_records_table.php
├── 2024_01_01_000010_create_adm_records_table.php
├── 2024_01_01_000011_create_real_estate_tax_rate_records_table.php
├── 2024_01_01_000012_create_selected_data_records_table.php
└── 2024_01_01_000013_create_act_one_index_records_table.php

TEMPLATE_MIGRATIONS.md  # This file - quick reference
STORING_PDE_DATA.md      # Complete guide to fetching and storing
```

## Need Help?

1. **Quick reference** → See the table at the top of this file
2. **Migration details** → Read `migrations/README.md`
3. **How to fetch & store data** → See `STORING_PDE_DATA.md`
4. **Query building** → See main `README.md` for query API
5. **Data classes** → Check the `src/` directory for docblocks explaining fields
