# PDE Client Template Migrations

This directory contains template migrations for storing Pennsylvania Department of Education (PDE) data obtained from the various PDE datasets provided by this package.

## Overview

These migrations are **reference templates** and are **not included** in the service provider configuration. They are provided to help you design your own database schema for storing PDE data in your application.

Each migration corresponds to a data type that the PDE Client package can parse and query:

## Migrations

### Financial Data

1. **`2024_01_01_000001_create_financial_records_table.php`** - GFB (budgeted) and AFR (actual) financial data
   - One account line per LEA per fiscal year
   - Includes budget and actual amounts for revenue, expenditure, and fund balance accounts
   - Follows Pennsylvania's Chart of Accounts

2. **`2024_01_01_000002_create_indebtedness_records_table.php`** - Statement of Indebtedness
   - Debt information by fund type and phase
   - Categories breakdown by PDE's debt category labels
   - From AFR (Actual Financial Report) dataset

3. **`2024_01_01_000003_create_fund_balance_records_table.php`** - Fund Balance data
   - Fund balance by classification (committed, assigned, unassigned)
   - General-fund classifications; one record per district/year
   - From AFR (Actual Financial Report) dataset

### Assessment Data

4. **`2024_01_01_000004_create_assessment_records_table.php`** - PSSA and Keystone exam results
   - Proficiency percentages by exam, subject, grade, and student group
   - Advanced, Proficient, Basic, and Below Basic percentages
   - Percentages suppressed (null) for groups under 11 students

### Graduation Data

5. **`2024_01_01_000005_create_graduation_records_table.php`** - Graduation rates
   - Cohort graduation rates (4-year, 5-year, 6-year)
   - By student demographic groups
   - Graduates and cohort size only available for 'Total' group

6. **`2024_01_01_000006_create_dropout_records_table.php`** - Dropout rates
   - District dropout totals, enrollment, and male/female counts
   - For grades 7-12

### Enrollment Data

7. **`2024_01_01_000007_create_enrollment_records_table.php`** - General, projected, and English learners
   - Enrollment counts by grade (normalized to PK/K/1-12)
   - Sub-counts preserve category breakdowns (e.g., kindergarten AM/PM/full-day)
   - Can include general, projected, and English learners data from one query

8. **`2024_01_01_000008_create_economically_disadvantaged_records_table.php`** - Economically disadvantaged student counts
   - From the Enrollment dataset
   - Includes sub-count breakdowns if available

### Personnel Data

9. **`2024_01_01_000009_create_personnel_records_table.php`** - Professional staff data
   - Headcounts by staff category (professional, administrator, classroom teacher, coordinator, other)
   - Gender breakdown
   - Averages: salary, years of service, years in LEA, education level

### Financial Data Elements

10. **`2024_01_01_000010_create_adm_records_table.php`** - Average Daily Membership (ADM)
    - ADM and Weighted ADM (WADM)
    - Category breakdown preserves PDE's per-category detail
    - Optional nonresident/total/special-education ADM availability is determined from recognized report columns

11. **`2024_01_01_000011_create_real_estate_tax_rate_records_table.php`** - Real estate tax rates
    - Tax rates per $1,000 of assessed value
    - Important for understanding local funding sources

12. **`2024_01_01_000012_create_selected_data_records_table.php`** - Miscellaneous selected data elements
    - Flexible JSON storage for various financial and operational metrics
    - Aid ratios, ADM/WADM, equalized mills, density, expenditure measures, and published ranks

13. **`2024_01_01_000013_create_act_one_index_records_table.php`** - Act One Index data
    - The adjusted index fraction, e.g. 0.041 = 4.1%
    - Flexible JSON storage for breakdowns and related metrics

## Using These Templates

To use these migrations in your application:

1. Copy the migrations you need to your application's `database/migrations` directory
2. Update the timestamp prefixes to match your migration naming convention
3. Adjust column types and constraints to match your application's requirements
4. Run `php artisan migrate` to create the tables

## Key Design Considerations

### Unique Constraints

Most tables include unique constraints on (aun, school_year, [other identifiers]) to ensure:

- Only one record per district per year for a given combination
- Prevention of duplicate data ingestion
- Integrity when updating data from PDE

### Flexible Storage with JSON

Some tables use JSON columns to store:

- Sub-counts (enrollment category breakdowns)
- Category breakdowns (indebtedness, ADM)
- Property and metric availability reasons; selected data and Act 1 use named numeric columns

This approach allows you to:

- Store variable data structures across different years
- Query JSON data using Laravel's JSON query operators
- Avoid rigid schema changes as PDE's data structure evolves

### Nullable Columns

Many columns are nullable because:

- PDE suppresses data for small populations
- Some columns are only available in certain school years
- Datasets may not have been requested in a particular query

### Indexes

All tables include indexes on:

- `[aun, school_year]` - for filtering by district and year
- Category/type columns - for grouping queries
- Unique constraints - for data integrity

## Related Classes

These migrations correspond to the following Laravel Data classes in the package:

- `FinancialRecord` → financial_records table
- `IndebtednessRecord` → indebtedness_records table
- `FundBalanceRecord` → fund_balance_records table
- `AssessmentRecord` → assessment_records table
- `GraduationRecord` → graduation_records table
- `DropoutRecord` → dropout_records table
- `EnrollmentRecord` → enrollment_records table
- `EconomicallyDisadvantagedRecord` → economically_disadvantaged_records table
- `PersonnelRecord` → personnel_records table
- `AdmRecord` → adm_records table
- `RealEstateTaxRateRecord` → real_estate_tax_rate_records table
- `SelectedDataRecord` → selected_data_records table
- `ActOneIndexRecord` → act_one_index_records table

## Notes

- All monetary values are stored as `decimal(18, 4)` for accuracy with financial data
- Percentages are stored as `decimal(18, 4)` (0-100 range)
- Rates/fractions are stored as `decimal(18, 6)` (0-1 range) unless otherwise noted
- The `aun` (Administrative Unit Number) is the primary identifier for Pennsylvania school districts
- `school_year` and `fiscal_year` follow the format `YYYY-YYYY` (e.g., `2023-2024`)

Templates match flat DTO field names and include an optional availability JSON column. Populate it explicitly in a consumer importer; null values alone lose historical meaning. [Endpoint definitions](../docs/index.md) and [the upgrade guide](../docs/upgrade.md) explain the collection and MetricMap APIs.
