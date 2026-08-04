# wiserwebsolutions/pde-client

Fluent Laravel client for discovering, downloading, and querying data files
published by the Pennsylvania Department of Education (PDE). Eleven datasets
so far, organized into five categories:

- **`->financials()`** — GFB (General Fund Budget) and AFR (Annual Financial
  Report actuals) budget/actual data (the category's primary dataset), plus
  four sibling datasets reached via sub-methods:
  - `->fundBalance()` — year-end general fund balance (committed/assigned/
    unassigned), from the AFR detail workbook family
  - `->indebtedness()` — Statement of Indebtedness (short- and long-term debt
    by fund type and phase), also from the AFR detail workbook family
  - `->realEstateTaxRates()` (or `->taxRates()`) — millage rates per district
    (per county, where a district spans more than one)
  - `->selectedData()` — aid ratio, WADM/ADM, equalized mills, population
    density, and PDE's own raw per-pupil expenditure figures (Actual
    Instruction Expense per WADM, Total Expenditures per ADM)
- **`->enrollments()`** — public school enrollment, enrollment projections,
  and English learner counts (the category's primary dataset), plus two
  sibling datasets:
  - `->lowIncome()` — low-income (economically disadvantaged) student counts
  - `->averageDailyMembership()` (or `->adm()`) — ADM/WADM per district
- **`->assessments()`** — PSSA (grades 3-8) and Keystone (grade 11) district
  proficiency results (the category's primary dataset), plus:
  - `->graduation()` — 4/5/6-year cohort graduation rates and dropout summaries
- **`->personnel()`** — professional staff summary reports (full-time
  headcounts and salary/experience averages per staff category)
- **`->community()`** — placeholder category, no dataset wired up yet

Each of the eleven underlying datasets otherwise publishes one xlsx per school
year, except enrollment projections and low-income counts, which are each a
single workbook PDE updates in place.

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
PDE::district('101260303')->enrollments()->projections(false)->get();
PDE::district('101260303')->assessments()->pssa()->allStudents()->get();
PDE::district('101260303')->assessments()->graduation()->group('Total')->get();
PDE::district('101260303')->personnel()->classroomTeachers()->get();
PDE::district('101260303')->enrollments()->lowIncome()->get();
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

Pick budget/actual and a category, get back a `Collection` of
`FinancialRecord`s. The workbooks a query needs are downloaded (once) and
parsed (cached) automatically.

```php
// Every account for a district, most recent year published, budget and actual side by side
PDE::district('101260303')->financials()->get();

// One year
PDE::district('101260303')->year('2024-2025')->financials()->get();

// Actual amounts for all revenue accounts, most recent year
PDE::district('101260303')->financials()->actual()->revenues()->get();

// Budgeted amounts for all expenditure functions, one year
PDE::district('101260303')->year('2019-2020')->financials()->budget()->expenses()->get();

// One account line; variance() = actual - budget
$line = PDE::district('101260303')->year('2024-25')->financials()->account('6111')->sole();
$line->budget; $line->actual; $line->variance();

// Defaults: configured district, most recent year published for the requested measure(s)
PDE::query()->financials()->actual()->revenues()->total();

// Every year published for the requested measure(s) instead of just the most recent
PDE::district('101260303')->financials()->allYears()->actual()->revenues()->get();
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

Get back a `Collection` of `EnrollmentRecord`s, broken down per grade
(normalized to **PK, K, 1-12** — see "Grade normalization" below).

```php
// Every grade, most recent year available, actual + projected, for the default district
PDE::district()->enrollments()->get();

// One year
PDE::district()->year('2023-2024')->enrollments()->get();

// Every year available instead of just the most recent
PDE::district()->enrollments()->allYears()->get();

// Actuals only / projections only
PDE::district()->enrollments()->projections(false)->get();   // exclude projections
PDE::district()->enrollments()->projections()->get();        // projections only

// English learner counts instead of general enrollment
PDE::district()->enrollments()->englishLearners()->get();    // or ->english_learners()

// One grade
$k = PDE::district()->year('2024-2025')->enrollments()->grade('K')->sole();
$k->count;       // normalized total
$k->subCounts;   // ['K5A' => 8, 'K5F' => 120, ...] - the raw AM/PM/full-day columns summed into it
```

Notes on the data model:

- Omitting `year()` returns just the most recent year available for whatever
  population is selected - call `->allYears()` (or `->years()` /
  `->year('all')`) for every year instead. General enrollment, projections,
  and English learners each publish a different year range, so "most recent"
  depends on what's chosen (see below).
  **⚠️ Warning:** the first `->allYears()` call will download and parse
  every available workbook for that population — enrollment (19 files),
  projections (1 multi-year workbook), or English learners (13 files). This
  takes 30–60 seconds. Subsequent calls hit the cache. Prefer a single
  explicit `->year(...)` (or the most-recent-year default) in
  latency-sensitive code paths.
- `isProjection` distinguishes actual from projected rows; `dataset`
  distinguishes general enrollment from English learners.
- Available year ranges (as of this writing): public enrollment 2007-08
  onward (`.xls` for years through 2010-11, `.xlsx` after; 2004-05 through
  2006-07 have no AUN column at all - LEAs are identified by name only in a
  nested county/district/school outline this package has no name-to-AUN
  crosswalk for, so those three years are silently skipped rather than
  supported); projections 2020-21 onward (both actual and projected rows —
  only projected rows are used here, since the actual rows just duplicate
  public enrollment); English learners 2013-14 onward. No English learner
  projections exist at all. `->englishLearners()->projections()` is a valid
  query that simply returns an empty collection.

### Querying low-income enrollment

One `LowIncomeRecord` per district per year - low-income (economically
disadvantaged) student count, alongside the same-year total enrollment PDE
used as the percentage's denominator. A sibling of Enrollment in the
`enrollments` category, reached via `->enrollments()->lowIncome()`.

```php
PDE::district()->enrollments()->lowIncome()->get();                          // most recent year (2016-17 onward)
PDE::district()->year('2024-2025')->enrollments()->lowIncome()->sole()->percentLowIncome;
PDE::district()->enrollments()->lowIncome()->allYears()->get();              // every year published
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
   `EnrollmentQuery::someNewDataset()` alongside `lowIncome()` and
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
