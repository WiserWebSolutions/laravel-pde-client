# Budgets and actuals in one object

[Documentation index](index.md) · [GFB budget source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/general-fund-budget-gfb-data) · [AFR actual source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/summary-of-annual-financial-report-data/afr-data-detailed)

## Access

Use budgetAndActual() to load GFB budgets and AFR actuals together. Matching accounts become one FinancialRecord with budget and actual properties, nested in a FinancialYearSummary. This is also the default when neither budget() nor actual() is called. budget()/actual() select just one measure; budgetAndActual() restores both after either selector.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->budgetAndActual()->revenues()->account('6111');
$results = $query->get(); // Collection<FinancialYearSummary>
$result = $results->sole(); // One district/year object
$account = $result->accounts->sole(); // One selected FinancialRecord

$budget = $account->budget; // 1000.0 in the synthetic fixture
$actual = $account->actual; // 1100.0 in the synthetic fixture
$variance = $account->variance(); // 100.0: actual minus budget, in USD

$first = $query->first(); // FinancialYearSummary|null
$only = $query->sole(); // Requires exactly one matching year
$flat = $query->records(); // LazyCollection<FinancialRecord>, both amounts on each row
$json = $result->toJson();
```

Remove account() to include all accounts. Use expenditures()/expenses() for spending. Existing account/category filters and optional withFundBalance(), withIndebtedness(), withTaxRates(), withSelectedData(), or withActOneIndex() inclusions work with the combined selector. withAllDatasets() includes those sibling datasets; budgetAndActual() controls the two financial measures.

Records match by district AUN, fiscal year, category, and account/function code. Budgets and actuals roll up independently. GFB expenditure function/object amounts are aggregated to the function code used by AFR expenditure records. Account detail available in only one measure remains in the result with the other measure null. Matching never uses another district or fiscal year to fill a gap.

## Serialized example

These are synthetic fixture values, not statistics for the named AUN. DocumentationExamplesTest checks the access chain, properties, totals, and complete JSON below. get() returns a collection; this JSON represents its sole year summary. Methods such as variance() are calculated on demand and are not extra serialized fields.

```json
{
    "aun": "124157203",
    "district_name": "Fixture District",
    "county": "Fixture County",
    "fiscal_year": "2024-2025",
    "fund_balance": null,
    "indebtedness": null,
    "real_estate_tax_rates": null,
    "selected_data": null,
    "act_one_index": null,
    "accounts": [
        {
            "aun": "124157203",
            "district_name": "Fixture District",
            "county": "Fixture County",
            "fiscal_year": "2024-2025",
            "category": "revenue",
            "account_code": "6111",
            "account_name": "Current real estate taxes",
            "parent_code": "6110",
            "budget": 1000,
            "actual": 1100
        }
    ]
}
```

Both financial values are nullable USD amounts. Positive variance means actual exceeds budget, whether comparing revenue or spending. The [GFB](datasets/gfb-budgets.md) and [AFR](datasets/afr-actuals.md) pages define the shared account and summary fields.

## Years and missing amounts

Choose a fiscal year for which the source reports publish both measures when you need a complete comparison. No fixed historical cutoff is assumed. Omitting year() selects the newest year in the union of budget and actual coverage, which can have just one measure. allYears() returns chronological year summaries, including years with only budget or only actual data. sole() still requires exactly one year.

Inspect availability before reading a comparison:

```php
$budgetState = $account->availability('budget');
$actualState = $account->availability('actual');

if ($account->hasData('budget') && $account->hasData('actual')) {
    $variance = $account->variance();
}
```

A requested measure with no published matching account/year remains null with not_reported availability. A blank or suppressed amount retains its reported availability reason. Selecting only one measure marks the other not_requested. A published zero remains available and participates in variance; a nullable input makes variance null. Neither measure is substituted for the other.

Confirmed not_collected fields follow the [missing-data policy](missing-data.md): property access, variance calculation that reads the unavailable property, and exports including that property throw DataNotCollectedException under the default strict setting. With PDE_CLIENT_MISSING_DATA=return_null, newly fetched DTOs return/serialize null instead. Use hasData()/availability() for nonthrowing inspection and only()/except() to exclude fields. An unpublished year or account alone does not prove historical noncollection. Download and parsing failures remain errors in both modes.

## Separate totals

Choose a measure and category for each total, even when the query includes both:

```php
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Measure;

$budgetTotal = $query->total(Measure::Budget, FinancialCategory::Revenue); // 1000.0 in this fixture
$actualTotal = $query->total(Measure::Actual, FinancialCategory::Revenue); // 1100.0 in this fixture
```

The query retains year/account filters and avoids counting parents and descendants twice. See [totals and year selection](queries.md).
