# wiserwebsolutions/pde-client

Fluent Laravel client for discovering, downloading, and querying data files
published by the Pennsylvania Department of Education (PDE). Twelve datasets
so far, organized into five categories:

- **`->financials()`** — GFB (General Fund Budget) and AFR (Annual Financial
  Report actuals) budget/actual data (the category's primary dataset), plus
  five sibling datasets reached via sub-methods, and nestable straight into
  the primary query's own result via `->withX()`/`->withAllDatasets()` (see
  "Querying financial data" below):
  - `->fundBalance()` — year-end general fund balance (committed/assigned/
    unassigned), from the AFR detail workbook family
  - `->indebtedness()` — Statement of Indebtedness (short- and long-term debt
    by fund type and phase), also from the AFR detail workbook family
  - `->realEstateTaxRates()` (or `->taxRates()`) — millage rates per district
    (per county, where a district spans more than one)
  - `->selectedData()` — aid ratio, WADM/ADM, equalized mills, population
    density, and PDE's own raw per-pupil expenditure figures (Actual
    Instruction Expense per WADM, Total Expenditures per ADM)
  - `->actOneIndex()` — Act 1 adjusted index: the maximum property tax
    increase a district may levy in a school year without PDE exception or
    voter approval
- **`->enrollments()`** — public school enrollment, enrollment projections,
  and English learner counts (the category's primary dataset - see
  `->withEnglishLearners()`/`->onlyEnglishLearners()`/`->withAllDatasets()`
  below), plus two sibling datasets:
  - `->economicallyDisadvantaged()` — low-income (economically disadvantaged)
    student counts
  - `->averageDailyMembership()` (or `->adm()`) — ADM/WADM per district
- **`->assessments()`** — PSSA (grades 3-8) and Keystone (grade 11) district
  proficiency results (the category's primary dataset), plus:
  - `->graduation()` — 4/5/6-year cohort graduation rates and dropout summaries
- **`->personnel()`** — professional staff summary reports (full-time
  headcounts and salary/experience averages per staff category)
- **`->community()`** — placeholder category, no dataset wired up yet

Each of the twelve underlying datasets otherwise publishes one xlsx per school
year, except enrollment projections, economically disadvantaged counts, and
the Act 1 Index, which are each a single workbook PDE updates in place.

The scraping/downloading/caching core (`RemoteFile`, `DataSource`, the
`FileFinder`/`FileDownloader` contracts, `AbstractHtmlFinder`,
`FilesystemDownloader`, `LocalWorkbookStore`, `RowTable`) is domain-agnostic,
so further PDE data can become sibling modules later without touching any of
it. See "Extending" below.

## Installation

Install via Composer:

```bash
composer require wiserwebsolutions/laravel-pde-client
```

Laravel's package auto-discovery registers the service provider and the
`PDE` facade automatically. To customize page URLs, the download disk, the
default district, or cache TTLs, publish the config:

```bash
php artisan vendor:publish --tag=pde-client-config
```

## Usage

### The district/year context

`PDE::district()` and `PDE::query()` both return a `PendingQuery` — shared
district/year context that isn't tied to a dataset yet. `->financials()`,
`->enrollments()`, `->assessments()`, `->personnel()`, and `->community()`
branch off it into the category's primary fluent query. Every other dataset
in a merged category is reached via a sub-method on that primary query:

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

PDE::district('101260303')->year('2024-2025')->financials()->budget()->revenues()->get();
PDE::district('101260303')->enrollments()->withProjections()->get();
PDE::district('101260303')->assessments()->pssa()->allStudents()->get();
PDE::district('101260303')->assessments()->graduation()->group('Total')->get();
PDE::district('101260303')->personnel()->classroomTeachers()->get();
PDE::district('101260303')->enrollments()->economicallyDisadvantaged()->get();
PDE::district('101260303')->financials()->fundBalance()->get();

// district()/year() also work directly on any dataset query, in any order
PDE::query()->financials()->district('101260303')->year('2024-25')->account('6111')->sole();
```

`district()` called with no argument (or never called at all) falls back to
`config('pde-client.default_district')` (env `PDE_CLIENT_DEFAULT_AUN`).
`year()` called with no argument (or never called at all) resolves to the
single **most recent** year available for whatever's being queried. Call
`->allYears()` (aliases: `->years()`, `->year('all')`) instead for every year
available — the old default. An explicit `->year('2024-2025')` (or `'2024-25'`
or `2024`) always pins to that one year. A sibling reached via a sub-method
(e.g. `->financials()->fundBalance()`) carries over whatever district()/year()
selection was already made on the primary query.

### Querying financial data

Pick budget/actual and a category, get back one `FinancialYearSummary` per
fiscal year (or a single one directly, for a query that resolves to exactly
one year - see below), each nesting that year's account-code
`FinancialRecord`s in `accounts`. The workbooks a query needs are downloaded
(once) and parsed (cached) automatically.

```php
// Every account for a district, most recent year published, budget and actual side by side
PDE::district('101260303')->financials()->get();

// One year
PDE::district('101260303')->year('2024-2025')->financials()->get();

// Actual amounts for all revenue accounts, most recent year
PDE::district('101260303')->financials()->actual()->revenues()->get();

// Budgeted amounts for all expenditure functions, one year
PDE::district('101260303')->year('2019-2020')->financials()->budget()->expenses()->get();

// One account line; variance() = actual - budget - drill into accounts for the FinancialRecord itself
$line = PDE::district('101260303')->year('2024-25')->financials()->account('6111')->sole()->accounts->sole();
$line->budget; $line->actual; $line->variance();

// Defaults: configured district, most recent year published for the requested measure(s)
PDE::query()->financials()->actual()->revenues()->total();

// Every year published for the requested measure(s) instead of just the most recent
PDE::district('101260303')->financials()->allYears()->actual()->revenues()->get();

// Nest sibling dataset(s) straight into that year's FinancialYearSummary instead of querying them separately
PDE::district('101260303')->year('2024-2025')->financials()->withFundBalance()->withActOneIndex()->sole();
PDE::district('101260303')->year('2024-2025')->financials()->withAllDatasets()->sole();  // every sibling dataset nested in
```

Notes on the data model:

- Districts are keyed by their 9-digit **AUN** (Administrative Unit Number).
- `year()` takes `'2024-25'`, `'2024-2025'`, or `2024`; omitted, it returns
  just the single **most recent** year published for whatever measure(s) are
  selected - the same convention as every other dataset query (see "The
  district/year context" above). Call `->allYears()` (or `->years()` /
  `->year('all')`) for every year published instead. A year missing one
  measure (e.g. AFR actuals lagging the current GFB budget year by a year or
  more) simply produces records with that measure `null` rather than being
  left out entirely; `parent()`/`children()` only ever resolve against
  records from the *same* fiscal year, even when a query spans many.
  **⚠️ Warning:** the first `->allYears()` call downloads and parses every
  GFB and/or AFR workbook the requested measure(s) need across every
  published year - this can take a minute or more. Subsequent calls hit the
  cache. Prefer a single explicit `->year(...)` (or the most-recent-year
  default) in latency-sensitive code paths.
- **Budget** numbers come from that year's GFB workbook; **actual** numbers
  from the AFR detailed workbooks. Only the files a query needs are fetched.
- Expenditures are keyed by 4-digit *function* code (`1110`, `2500`, ...).
  AFR publishes actuals at function level only, so GFB budgets (function ×
  object) are summed to function level to line up with them.
- Both sources also publish rollup codes (`6000`, `1000`, `1100`, ...), which
  appear as their own records here too - see "Rollups and the account
  hierarchy" below for how their amounts are computed.
- `revenues()` covers 6000-9999 (incl. 9000 other financing sources);
  `expenses()`/`expenditures()` covers functions 1000-5999; `fundBalances()`
  covers the GFB's 08xx beginning-fund-balance codes (budget only).
- `get()`/`first()`/`sole()` don't return flat `FinancialRecord`s directly -
  a query that resolves to exactly one fiscal year (an explicit `->year(...)`,
  or the most-recent-year default) returns a single `FinancialYearSummary`
  straight from `get()`, not wrapped in a `Collection`; a multi-year query
  (`->allYears()`, or anything else matching more than one year) returns a
  `Collection<FinancialYearSummary>` instead - `first()`/`sole()` always give
  back a single `FinancialYearSummary` regardless (`sole()` throwing if more
  than one year matched). `total()` stays a flat, un-summarized sum across
  every matched account-code record, ignoring fiscal year boundaries.
- `->withFundBalance()`, `->withIndebtedness()`, `->withRealEstateTaxRates()`
  (or `->withTaxRates()`), `->withSelectedData()`, and `->withActOneIndex()`
  each nest that sibling dataset into every matched year's
  `FinancialYearSummary` instead of it being queried separately -
  `->withAllDatasets()` turns all five on at once, and each has a matching
  `->withoutX()` to turn it back off (mainly useful to undo one dataset after
  `->withAllDatasets()`). Every sibling field on `FinancialYearSummary` is
  `null` unless its `->withX()` was called (or PDE simply has no data for
  that year) - `->indebtedness` and `->realEstateTaxRates` are collections
  (a district/year can have more than one record - see IndebtednessRecord/
  RealEstateTaxRateRecord) rather than single records, so a `null` there
  means "not queried" and an empty `Collection` means "queried, nothing
  published for this year".

### Rollups and the account hierarchy

Every `FinancialRecord` carries `parentCode` and can walk the Chart of
Accounts hierarchy directly:

```php
$line = PDE::district('101260303')->year('2024-25')->financials()->account('6111')->sole();
$line->parentCode;       // '6110'
$line->parent();         // FinancialRecord for 6110 (Ad Valorem Taxes), or null at the top of the tree
$line->parent()?->parent()?->accountCode;  // '6100', walking further up
$line->children();       // Collection<FinancialRecord> - empty here, 6111 is a leaf
$line->isLeaf();         // true
```

`budget`/`actual` on a code with children are **always** the sum of that
code's children (recursively), not whatever the source itself reported at
that level - this matters because GFB (budget) never publishes a rollup's
amount at all, only leaf-level codes, so without this a query for e.g. `6000`
(Total Local Revenue) would come back empty for budget every time. AFR
(actual) does publish rollup totals directly, but they're recomputed the same
way here too, so budget and actual always reconcile against identical math
instead of two independently-sourced totals.

The hierarchy itself is bundled with the package (`resources/chart-of-accounts/*.json`,
trimmed from PDE's own Chart of Accounts manual) - no database or extra
install step needed. A handful of codes that show up in real AFR/GFB data
(mostly older or program-specific sub-codes, e.g. ARRA-era federal stimulus
sub-programs under 8700-8799) aren't in PDE's published manual at all; those
come through as parent-less records (`parentCode` null, `parent()`/`children()`
simply unavailable) rather than being dropped.

### Querying enrollment data

`get()` folds every dataset the query selected (general enrollment, English
learners, projections, and - with `->withEconomicallyDisadvantaged()` -
economically disadvantaged) into one `EnrollmentYearSummary` per school
year, nesting every matching per-grade `EnrollmentRecord` underneath in
`grades` (normalized to **PK, K, 1-12** — see "Grade normalization" below)
rather than discarding the detail. A query that resolves to exactly one
year (an explicit `->year(...)`, or the most-recent-year default) returns
that single `EnrollmentYearSummary` directly, not wrapped in a `Collection`;
a multi-year query (`->allYears()`, or anything else matching more than one
year) returns a `Collection` of them instead - `first()`/`sole()` always
give back a single `EnrollmentYearSummary` regardless (`sole()` throwing if
more than one year matched):

```php
// Single year -> a single EnrollmentYearSummary, not wrapped in a Collection
$year = PDE::district()->year('2024-2025')->enrollments()
    ->withEnglishLearners()
    ->withEconomicallyDisadvantaged()
    ->get();

$year->enrollmentTotal;                               // sum of count() across every actual general-enrollment grade
$year->englishLearnersTotal;                          // sum across every EL grade - populated, since withEnglishLearners() was called
$year->projectedEnrollmentTotal;                      // null - withProjections()/onlyProjections() wasn't called
$year->economicallyDisadvantagedTotal;                // the count, matching the naming of the other totals
$year->economicallyDisadvantaged?->percentEconomicallyDisadvantaged;  // the rest of that dataset's detail
$year->grades;                                        // Collection<EnrollmentRecord> - one row per grade, every selected dataset merged in

$k = $year->grades->firstWhere('grade', 'K');
$k->count;                    // general enrollment K count
$k->subCounts;                // ['K5A' => 8, 'K5F' => 120, ...] - the raw AM/PM/full-day columns summed into count
$k->englishLearnersCount;     // EL K count - populated on this same record, not a separate one
$k->projectedCount;           // null here - withProjections()/onlyProjections() wasn't called

// Multi-year -> a Collection<EnrollmentYearSummary>
$years = PDE::district()->enrollments()->allYears()->get();
$years->firstWhere('schoolYear', '2023-2024')->enrollmentTotal;

// Actual and projected, side by side / projections only
PDE::district()->enrollments()->withProjections()->get();    // actual AND projected rows together
PDE::district()->enrollments()->onlyProjections()->get();    // projected rows only, instead of actual
PDE::district()->enrollments()->withoutProjections()->get(); // actual only - the default; undoes with/onlyProjections()

// English learner counts
PDE::district()->enrollments()->withEnglishLearners()->get();  // general enrollment AND EL counts, side by side
PDE::district()->enrollments()->onlyEnglishLearners()->get();  // EL counts instead of general enrollment
PDE::district()->enrollments()->withAllDatasets()->get();      // every dataset this query can blend in, INCLUDING economically disadvantaged

// One grade
$k = PDE::district()->year('2024-2025')->enrollments()->grade('K')->sole();
$k->grades->sole()->count;       // normalized total
```

Notes on the data model:

- `EnrollmentRecord` merges every selected dataset into one row per grade -
  `count`/`subCounts` for general enrollment, `projectedCount`/
  `projectedSubCounts` for projections, `englishLearnersCount`/
  `englishLearnersSubCounts` for English learners - rather than emitting a
  separate record per dataset that happens to share a `grade`. Each is
  always present as a property, but only ever holds a value (with its
  matching subCounts populated) when that dataset was actually part of the
  query *and* PDE published data for that grade/year - otherwise it's
  `null`/`[]`, never a stray `0` or a missing property.
- Each total on `EnrollmentYearSummary` follows the same naming and
  null-unless-queried-and-published rule, one level up - it's the sum of its
  matching `EnrollmentRecord` field across every grade in `grades`. A
  `->grade('K')` filter narrows every total along with `grades`.
  `economicallyDisadvantagedTotal` follows the same rule too (it's just
  `economicallyDisadvantaged?->economicallyDisadvantagedCount`, named to
  match the other totals), and is further only ever populated for actual
  (non-projected) years, since PDE doesn't publish that dataset broken out
  by grade, by English learner status, or as a projection -
  `->withAllDatasets()` implies `->withEconomicallyDisadvantaged()`, but
  `->withEnglishLearners()`/`->withProjections()`/etc. don't, so it stays
  `null` unless one of those two was called.
- `total()` sums every dataset's count across every matched `EnrollmentRecord`
  regardless of dataset or actual/projected status - a flat grand total for
  when you don't need the per-dataset/per-year breakdown at all (mixing, say,
  general enrollment and English learners into one number if both were
  selected).
- Omitting `year()` returns just the most recent year available for whatever
  population(s) are selected - call `->allYears()` (or `->years()` /
  `->year('all')`) for every year instead. General enrollment, projections,
  and English learners each publish a different year range, so "most recent"
  depends on what's chosen (see below).
- Actual data only is the default for every year selection - bare
  `->enrollments()->get()`, `->allYears()`, and an explicit `->year(...)` all
  exclude projections unless you opt in. PDE's projections workbook reaches
  years ahead of the last actual year, and this query should surface real
  data by default rather than a projection. Call `->withProjections()` for
  actual and projected rows together, or `->onlyProjections()` for projected
  rows instead of actual (`->withoutProjections()` undoes either one, back
  to the default).
  **⚠️ Warning:** the first `->allYears()` call will download and parse
  every available workbook for that population — enrollment (19 files),
  projections (1 multi-year workbook), or English learners (13 files). This
  takes 30–60 seconds. Subsequent calls hit the cache. Prefer a single
  explicit `->year(...)` (or the most-recent-year default) in
  latency-sensitive code paths.
- Available year ranges (as of this writing): public enrollment 2007-08
  onward (`.xls` for years through 2010-11, `.xlsx` after; 2004-05 through
  2006-07 have no AUN column at all - LEAs are identified by name only in a
  nested county/district/school outline this package has no name-to-AUN
  crosswalk for, so those three years are silently skipped rather than
  supported); projections 2020-21 onward (both actual and projected rows —
  only projected rows are used here, since the actual rows just duplicate
  public enrollment); English learners 2013-14 onward. No English learner
  projections exist at all. `->onlyEnglishLearners()->onlyProjections()` is
  a valid query that simply returns an empty collection.

### Querying economically disadvantaged enrollment

One `EconomicallyDisadvantagedRecord` per district per year - economically
disadvantaged (low-income) student count, alongside the same-year total
enrollment PDE used as the percentage's denominator. A sibling of Enrollment
in the `enrollments` category, reached via
`->enrollments()->economicallyDisadvantaged()`.

```php
PDE::district()->enrollments()->economicallyDisadvantaged()->get();                          // most recent year (2016-17 onward)
PDE::district()->year('2024-2025')->enrollments()->economicallyDisadvantaged()->sole()->percentEconomicallyDisadvantaged;
PDE::district()->enrollments()->economicallyDisadvantaged()->allYears()->get();              // every year published
```

Sourced from PDE's single, in-place-updated "Ten Year Low Income and
Enrollment History" workbook rather than a per-year file - `enrollment` here
may differ slightly from the general enrollment dataset's own total, since
the two come from different PDE reports.

### Grade normalization

PDE doesn't publish grades consistently across datasets: general enrollment
and English learner counts split pre-K into AM/PM/full-day (`PKA`/`PKP`/`PKF`)
and kindergarten into 4- and 5-year-old AM/PM/full-day variants
(`K4A`/`K4P`/`K4F`/`K5A`/`K5P`/`K5F`), while projections has just a bare `K`
and no pre-K at all. `Grade::normalize()` collapses all of that to a single
**PK, K, 1-12** scale so datasets are comparable; each record's `subCounts`
keeps the raw columns that were summed into it, for callers that want the
AM/PM/full-day detail PDE actually reports.

### Querying assessment results

PSSA (grades 3-8) and Keystone (grade 11) district proficiency results as a
`Collection` of `AssessmentRecord`s, one per (exam, subject, tested grade,
student group), with the percentage of scored students in each proficiency
band (0-100, as PDE publishes; null where PDE suppressed populations under
11 students).

```php
// Both exams, every subject/grade/group, most recent published year
PDE::district()->assessments()->get();

// Every published year instead of just the most recent
PDE::district()->assessments()->allYears()->get();

// One exam, one subject, all-students only
PDE::district()->year('2024-2025')->assessments()->pssa()->subject('Math')->allStudents()->get();
PDE::district()->assessments()->keystone()->subject('Algebra I', 'Biology')->get();

// The aggregate row PDE publishes per subject ('Total' spans all tested grades)
$line = PDE::district()->year('2024-2025')->assessments()->pssa()
    ->subject('Math')->grade('Total')->allStudents()->sole();
$line->percentProficientOrAbove;
```

Years follow the package's school-year convention: PDE labels these files by
the calendar year of the spring testing window, so *their* "2025" file is
`year('2024-2025')` here. No 2019-2020 data exists (COVID cancelled that
administration). Groups are PDE's published cohorts ('All Students', 'Male',
'Female', race/ethnicity groups, 'ELL', 'IEP', 'Economically Disadvantaged').

### Querying graduation data

Cohort graduation rates as a `Collection` of `GraduationRecord`s, one per
student group per year, with the 'Total' group also carrying graduate and
cohort counts. Rates are fractions (0-1) as PDE stores them. A sibling of
Assessments in the `assessments` category, reached via
`->assessments()->graduation()`.

```php
PDE::district()->assessments()->graduation()->get();                        // 4-year rates (the standard), most recent year
PDE::district()->year('2023-2024')->assessments()->graduation()->group('Total')->sole()->rate;
PDE::district()->assessments()->graduation()->cohortYears(6)->get();        // students finishing within 6 years
PDE::district()->assessments()->graduation()->allYears()->get();            // every group/year published

// Dropout summaries instead (Collection<DropoutRecord>)
PDE::district()->assessments()->graduation()->dropouts()->get();
```

4-year rates exist 2010-11 onward, 5-year 2011-12, 6-year 2012-13; dropout
summaries 2007-08 onward (`.xls` through 2011-12, `.xlsx` after).

### Querying personnel data

Full-time professional staff summaries as a `Collection` of
`PersonnelRecord`s, one per staff category per year: headcounts by gender
plus average salary, years of service, LEA tenure, and education level.

```php
PDE::district()->personnel()->get();                          // every category, most recent year (2012-13 onward)
PDE::district()->year('2025-2026')->personnel()->classroomTeachers()->sole()->averageSalary;
PDE::district()->personnel()->administrators()->get();
PDE::district()->personnel()->category('coordinator', 'other')->get();
PDE::district()->personnel()->allYears()->get();               // every year published
```

Categories: `professional` (PDE's "PP" **total** of the other four don't
sum all five), `administrator`, `classroom_teacher`, `coordinator`, `other`.

### Querying Average Daily Membership (ADM)

One `AdmRecord` per district per year: ADM, WADM, Adjusted ADM, and (2024-25
onward) Nonresident ADM, total ADM for PDE-363, and Special Education ADM. A
sibling of Enrollment in the `enrollments` category, reached via
`->enrollments()->averageDailyMembership()`.

```php
PDE::district()->enrollments()->averageDailyMembership()->get();  // most recent year (2015-16 onward)
PDE::district()->year('2024-2025')->enrollments()->averageDailyMembership()->sole();
PDE::district()->enrollments()->adm()->sole()->wadm;               // adm() is a shorthand alias
PDE::district()->enrollments()->adm()->allYears()->get();          // every year published
```

`breakdown` carries the per-category ADM/WADM detail exactly as PDE publishes
it (`'ADM Kindergarten HT5' => 1.027`, `'WADM Elementary' => 1979.625`, ...) -
these categories (Pre-K/Kindergarten AM-PM-full-day splits, Elementary,
Secondary) are ADM-specific and don't line up with Enrollment's PK/K/1-12
grade scale, so they're kept raw rather than normalized against it.

### Querying real estate (millage) tax rates

One `RealEstateTaxRateRecord` per district per **county line** - a district
spanning more than one county publishes one rate per county, and a handful of
counties further split the rate by assessment type. A sibling of Financial in
the `financials` category, reached via `->financials()->realEstateTaxRates()`.

```php
PDE::district()->financials()->realEstateTaxRates()->get();     // most recent year, every county line (2016-17 onward)
PDE::district()->year('2024-2025')->financials()->realEstateTaxRates()->sole()->mills;
PDE::district()->financials()->taxRates()->get();                // taxRates() is a shorthand alias
PDE::district()->financials()->taxRates()->allYears()->get();    // every year published
```

PDE's own "Municipality / Other Info" column is genuinely mixed-purpose - real
municipality/township names, an assessment-type split ("Buildings"/"Land"),
an "Oil/Gas/Mineral Properties" carve-out, or a fiscal-year note, depending on
the row - so it's kept verbatim as a nullable `notes` field rather than forced
into a municipality-only column. `communityCollegeMills` is null wherever a
district has no additional community college levy.

### Querying general fund balance

One `FundBalanceRecord` per district per year - the year-end general fund
balance, broken into committed/assigned/unassigned (account codes
0830/0840/0850) as reported in the AFR. Not to be confused with
`FinancialQuery::fundBalances()`, which covers the GFB's entirely different
*beginning*-of-year budgeted 08xx codes. A sibling of Financial in the
`financials` category, reached via `->financials()->fundBalance()`.

```php
PDE::district()->financials()->fundBalance()->get();                          // most recent year (2015-16 onward)
PDE::district()->year('2024-2025')->financials()->fundBalance()->sole()->total();  // sum of whichever fields are present
PDE::district()->financials()->fundBalance()->allYears()->get();              // every year published
```

### Querying indebtedness (Statement of Indebtedness)

`IndebtednessRecord`s broken down by fund type and phase - up to 10 per
district per year: 2 "all fund types" summary lines (`fundType: 'all'`,
`phase: 'beginning'|'end'`) plus 4 phases each (`'beginning'`, `'additional'`,
`'retirements'`, `'end'`) for `'governmental'` and `'proprietary'` fund types.
A sibling of Financial in the `financials` category, reached via
`->financials()->indebtedness()`.

```php
PDE::district()->financials()->indebtedness()->get();                                              // most recent year, every combination
PDE::district()->year('2024-2025')->financials()->indebtedness()->fundType('governmental')->phase('end')->sole()->total;
PDE::district()->financials()->indebtedness()->allYears()->get();                                  // every year published
```

`categories` breaks `total` down by PDE's own debt category labels for that
year, kept verbatim - the specific categories changed across years (2015-16:
Other Long-Term Debt / OPEB / Compensated Absences / Net Pension Liability as
four separate lines; 2024-25 onward: consolidated into fewer, differently-
named categories, plus new Leases and Extended Term Financing Agreements
lines) - a real reporting methodology change, not cosmetic drift, so nothing
is forced into a single cross-year taxonomy. Every `total` (including both
"all fund types" lines) is computed from the underlying category values
rather than read from the source workbook - PDE's own TOTAL cells are
unevaluated spreadsheet formulas with no cached result to read.

### Querying Selected Data (including per-pupil expenditure)

One `SelectedDataRecord` per district per year - a bundle of headline metrics
PDE publishes together: aid ratio, WADM/ADM, equalized mills, population
density, and PDE's own two raw per-pupil expenditure figures. A sibling of
Financial in the `financials` category, reached via
`->financials()->selectedData()`.

```php
PDE::district()->financials()->selectedData()->get();                      // most recent year (2013-14 onward)
PDE::district()->year('2022-2023')->financials()->selectedData()->sole()->instructionExpensePerWadm;  // Actual Instruction Expense per WADM
PDE::district()->year('2022-2023')->financials()->selectedData()->sole()->totalExpenditurePerAdm;     // Total Expenditures per ADM
PDE::district()->financials()->selectedData()->allYears()->get();          // every year published
```

Every metric except `wadm` is paired with its own `*Rank` field (statewide
rank, 1 = highest) - PDE's own Rank cells are also unevaluated spreadsheet
formulas (`=RANK(D2,D$2:D$502)`), but this workbook *does* carry a cached
result for them, so they read correctly without needing any special handling
in this package - see "A note on spreadsheet formulas" below. `aidRatio` is
frequently labeled for a different (often later) school year than the rest
of the row, matching PDE's own presentation.

### Querying the Act 1 Index

One `ActOneIndexRecord` per district per year - the maximum property tax
increase that district may levy without PDE exception or voter approval. A
sibling of Financial in the `financials` category, reached via
`->financials()->actOneIndex()`.

```php
PDE::district()->financials()->actOneIndex()->get();                          // most recent year (2015-16 onward)
PDE::district()->year('2024-2025')->financials()->actOneIndex()->sole()->index;
PDE::district()->financials()->actOneIndex()->allYears()->get();              // every year published
```

`index` is already the *adjusted* index PDE publishes per district - a
fraction (e.g. `0.041` for 4.1%), already multiplied by `0.75 + MV/PI aid
ratio` for districts PDE adjusts upward (aid ratio over `0.4000`). PDE's
separate statewide *base* index (a single percentage per year with no
per-district breakdown) isn't modeled here, since it has no district
dimension to query by - `PDE::actOneIndexFiles()->category('base_index_history')`
still discovers/downloads that PDF directly if you need it.

Sourced from PDE's single, in-place-updated "Adjusted Index History"
workbook rather than a per-year file, the same pattern as economically
disadvantaged enrollment.

### Discovering and downloading files directly

The lower-level API the query layers are built on:

```php
// GFB - one file per school year
$latest = PDE::gfb()->latest();
PDE::gfb()->schoolYear('2024-25')->download();

// AFR - grouped by category, multi-year workbooks
PDE::afr()->revenues()->get(); // Collection<RemoteFile>
PDE::afr()->matching('Local Revenue')->sole()->url;
PDE::afr()->expenditures()->download(disk: 's3', directory: 'afr/expenditures');

// Enrollment - categorized by URL path (public, projections, english_learners, ...)
PDE::enrollmentFiles()->category('public')->matching('2024-2025')->sole()->url;
PDE::enrollmentFiles()->matching('School District Enrollment Projections')->sole()->download();

// Assessments (pssa, keystone), graduation (cohort, dropouts), personnel
// (staff_summary, individual, ...) follow the same pattern - e.g. the ~35MB
// per-year individual staff reports are downloadable even though the query
// layer doesn't model them:
PDE::personnelFiles()->category('individual')->matching('2025-26')->sole()->download();

// Financial data elements - categorized by URL path (average_daily_membership,
// real_estate_tax_rates, selected_data, aid_ratios, personal_income); the
// last two are discoverable/downloadable but not modeled in the query layer:
PDE::financialDataElementsFiles()->category('aid_ratios')->matching('2024-25')->sole()->download();

// Act 1 Index - categorized by filename (adjusted_index_history is the
// per-district, multi-year workbook the query layer is built on;
// adjusted_index_current and base_index_history are discoverable/
// downloadable but not modeled):
PDE::actOneIndexFiles()->category('base_index_history')->sole()->download();
```

Every terminal method (`get()`, `first()`, `sole()`, `download()`) operates on
whatever filters were chained before it — `category()` and `matching()` are
available on every source; `schoolYear()`/`latest()` are GFB-specific,
`revenues()`/`expenditures()`/`miscellaneous()`/`fullReports()` are
AFR-specific category shortcuts.

`download()` streams straight from PDE to whichever Laravel filesystem disk
you point it at (defaults to `config('pde-client.disk')`), without buffering
the whole file in memory.

## Extending to a new PDE data module

The enrollment module (`src/Enrollment/`) is the template for adding another
one (financial data, `src/FinancialData/`, is the original, enrollment
copies its shape almost exactly):

1. Add a `Finders\SomeNewFileFinder extends \WiserWebSolutions\PDEClient\Finders\AbstractHtmlFinder`
   implementing `parseDocument(HtmlDocument $document): Collection` — the
   only method that needs to know how that specific page is laid out.
2. Add a `SomeNewFiles extends \WiserWebSolutions\PDEClient\DataSource`
   implementing `defaultDirectory()` for raw file listing/downloading, and a
   `SomeNewFileLocator` (via `Support\LocalWorkbookStore`) to resolve a
   `RemoteFile` to a local path.
3. Add parser(s) that turn a downloaded workbook into a
   `FinancialData\Parsing\YearTable` (reused as-is — it's just
   `districts + amounts[key][code]` plain arrays, already cache-safe) and a
   `SomeNewDataRepository` that caches parsed tables per year (mirror
   `EnrollmentDataRepository`).
4. Add a `SomeNewRecord` DTO and `SomeNewQuery implements
   \WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext`, using
   `\WiserWebSolutions\PDEClient\Concerns\HasQueryContext` for the
   district()/year()/allYears() plumbing, with whatever fluent filters make
   sense for the dataset.
5. Bind the new Finder in `PDEClientServiceProvider::register()` (it needs a
   page URL, same as the existing finders), then wire the dataset in either
   as a brand new category branch on `PendingQuery` (e.g. the first real
   dataset under `->community()`, replacing `CommunityQuery`), or as a
   sibling sub-method on an existing category's primary query (e.g. adding
   `EnrollmentQuery::someNewDataset()` alongside `economicallyDisadvantaged()` and
   `averageDailyMembership()`, seeded via `$this->seedSibling(...)`).

Nothing about filtering, caching, HTTP fetching, or downloading needs to be
touched. That's all in `AbstractHtmlFinder` and `DataSource`.

PA.gov's pages are built on the same Adobe Experience Manager template, which
means every page repeats the same sidebar `<nav>`, "the .gov means it's
official" `<dialog>`, and global footer — all of which can contain stray
headings/links that a plain `//h2` or `//a[...]` XPath query would pick up
alongside the page's real content (this bit both the financial-data Finders
during development; see `AbstractHtmlFinder::excludingChrome()`). Wrap any
XPath predicate you write with it, e.g.
`"//a[".self::excludingChrome("substring(@href, ...) = '.xlsx'")."]"`.

### A note on spreadsheet formulas

Several PDE workbooks contain formula cells (`=SUM(...)`, `=RANK(...)`) that
were evidently never opened in Excel to compute before being published, or
were - it varies by file, and sometimes by column within the same file.
`SpreadsheetReader` (`.xlsx` via openspout) already prefers a formula cell's
cached computed value when the workbook has one, so most Parsers never need
to think about this at all. When a workbook genuinely has no cached value
(confirmed on the Statement of Indebtedness workbook's TOTAL cells - see
`IndebtednessParser`), the reader falls back to the raw formula text, which
will silently fail `is_numeric()`/`is_int()`/`is_float()` checks and come
through as `null` - if a new Parser's numeric column looks suspiciously empty
across every row, dump a raw cell value first to rule this out before
assuming a header-matching bug. The fix is to compute the value yourself from
the same cells the formula would have referenced (`IndebtednessParser`
sums its own category columns; an Excel-compatible `RANK()` would need a full
column of values and tie-aware ranking - not currently needed anywhere, since
every Rank column encountered so far has had a usable cached value).

## Testing

This package ships without tests pre-written for the live Finders, since they
were verified against PDE's real pages during development (structure can
drift if PDE redesigns the site). If you add tests, `Http::fake()` the
listing page URLs with saved HTML fixtures and assert on `Finder::find()`'s
resulting `RemoteFile` collection. No network access needed at test time.
