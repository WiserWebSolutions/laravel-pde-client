# wiserwebsolutions/pde-client

Fluent Laravel client for discovering, downloading, and querying data files
published by the Pennsylvania Department of Education (PDE). Five datasets so far:

- **Financial** — GFB (General Fund Budget, one xlsx per school year) and AFR
  (Annual Financial Report actuals, xlsx files grouped by category)
- **Enrollment** — public school enrollment, enrollment projections, and
  English learner counts, one xlsx per school year (projections is a single
  workbook PDE updates in place)
- **Assessments** — PSSA (grades 3-8) and Keystone (grade 11) district
  proficiency results, one xlsx per exam administration
- **Graduation** — 4/5/6-year cohort graduation rates and dropout summaries,
  one xlsx per school year
- **Personnel** — professional staff summary reports (full-time headcounts
  and salary/experience averages per staff category), one xlsx per school year

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
district/year context that isn't tied to a dataset yet. `->financial()`,
`->enrollments()`, `->assessments()`, `->graduation()`, and `->personnel()`
branch off it into the dataset-specific fluent query:

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

PDE::district('101260303')->year('2024-2025')->financial()->budget()->revenues()->get();
PDE::district('101260303')->enrollments()->projections(false)->get();
PDE::district('101260303')->assessments()->pssa()->allStudents()->get();
PDE::district('101260303')->graduation()->group('Total')->get();
PDE::district('101260303')->personnel()->classroomTeachers()->get();

// district()/year() also work directly on any dataset query, in any order
PDE::query()->financial()->district('101260303')->year('2024-25')->account('6111')->sole();
```

`district()` called with no argument (or never called at all) falls back to
`config('pde-client.default_district')` (env `PDE_CLIENT_DEFAULT_AUN`); omitting
`year()` has a dataset-specific default (see each section below).

### Querying financial data

Pick budget/actual and a category, get back a `Collection` of
`FinancialRecord`s. The workbooks a query needs are downloaded (once) and
parsed (cached) automatically.

```php
// Every account for a district+year, budget and actual side by side
PDE::district('101260303')->year('2024-2025')->financial()->get();

// Actual amounts for all revenue accounts
PDE::district('101260303')->year('2024-2025')->financial()->actual()->revenues()->get();

// Budgeted amounts for all expenditure functions
PDE::district('101260303')->year('2019-2020')->financial()->budget()->expenses()->get();

// One account line; variance() = actual - budget
$line = PDE::district('101260303')->year('2024-25')->financial()->account('6111')->sole();
$line->budget; $line->actual; $line->variance();

// Defaults: configured district + latest year with BOTH budget and actual published
PDE::query()->financial()->actual()->revenues()->total();
```

Notes on the data model:

- Districts are keyed by their 9-digit **AUN** (Administrative Unit Number).
- `year()` takes `'2024-25'`, `'2024-2025'`, or `2024`; omitted, it resolves
  to the latest year published for the requested measures (actuals lag
  budgets, so a merged query resolves to the newest year that has *both*).
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
$line = PDE::district('101260303')->year('2024-25')->financial()->account('6111')->sole();
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
// Every grade, every year available, actual + projected, for the default district
PDE::district()->enrollments()->get();

// One year
PDE::district()->year('2023-2024')->enrollments()->get();

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

- Omitting `year()` returns **every** year available for whatever population
  is selected. General enrollment, projections, and English learners each
  publish a different year range, so "every year" depends on what's chosen
  (see below). This differs from the financial query's latest-year default.
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
// Both exams, every subject/grade/group, every published year
PDE::district()->assessments()->get();

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
cohort counts. Rates are fractions (0-1) as PDE stores them.

```php
PDE::district()->graduation()->get();                        // 4-year rates (the standard), every group/year
PDE::district()->year('2023-2024')->graduation()->group('Total')->sole()->rate;
PDE::district()->graduation()->cohortYears(6)->get();        // students finishing within 6 years

// Dropout summaries instead (Collection<DropoutRecord>)
PDE::district()->graduation()->dropouts()->get();
```

4-year rates exist 2010-11 onward, 5-year 2011-12, 6-year 2012-13; dropout
summaries 2007-08 onward (`.xls` through 2011-12, `.xlsx` after).

### Querying personnel data

Full-time professional staff summaries as a `Collection` of
`PersonnelRecord`s, one per staff category per year: headcounts by gender
plus average salary, years of service, LEA tenure, and education level.

```php
PDE::district()->personnel()->get();                          // every category, every year (2012-13 onward)
PDE::district()->year('2025-2026')->personnel()->classroomTeachers()->sole()->averageSalary;
PDE::district()->personnel()->administrators()->get();
PDE::district()->personnel()->category('coordinator', 'other')->get();
```

Categories: `professional` (PDE's "PP" **total** of the other four don't
sum all five), `administrator`, `classroom_teacher`, `coordinator`, `other`.

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
   \WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext` (so
   `PendingQuery` can seed it) with whatever fluent filters make sense for
   the dataset.
5. Bind the new Finder in `PDEClientServiceProvider::register()` (it needs a
   page URL, same as the existing finders) and add accessor methods to
   `PDEClientManager` (raw files + a `PendingQuery::someNewDataset()` branch).

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

## Testing

This package ships without tests pre-written for the live Finders, since they
were verified against PDE's real pages during development (structure can
drift if PDE redesigns the site). If you add tests, `Http::fake()` the
listing page URLs with saved HTML fixtures and assert on `Finder::find()`'s
resulting `RemoteFile` collection. No network access needed at test time.
