# GFB budgets

[Documentation index](../index.md) · [PDE source](https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/general-fund-budget-gfb-data)

## Access

Pass a district and a year explicitly for reproducible results. Without year(), the newest available year is selected; allYears() returns chronological history.

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

$query = PDE::district('124157203')->year('2024-2025')->financials()->budget()->revenues()->account('6111');
$results = $query->get(); // Collection<FinancialYearSummary>
$result = $results->sole();
$value = $result->accounts->sole()->budget; // 1000

$first = $query->first(); // FinancialYearSummary|null
$only = $query->sole(); // FinancialYearSummary; throws unless exactly one result matches
$records = $query->records(); // LazyCollection<FinancialRecord>, flat records
```

Budgets are USD amounts. Revenues and expenditures are separate categories; use expenditures()/expenses() for spending and fundBalances() for GFB beginning fund balance codes.

## Example data

These values are synthetic fixtures, not statistics for the named AUN. The complete access chain, PHP property value, and this JSON are checked in DocumentationExamplesTest. The JSON below is one DTO; get() returns a collection containing DTOs.

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
            "actual": null,
            "provenance": {
                "sources": [
                    {
                        "id": "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982",
                        "label": "Synthetic gfb workbook",
                        "url": "https://fixtures.example/gfb.xlsx",
                        "page_url": "https://fixtures.example/reports/gfb",
                        "period": "2024-2025",
                        "sha256": null,
                        "downloaded_at": null
                    },
                    {
                        "id": "67442af790cfd54d6fd539ea1608c0a86db38e950ac2c5d7de21df9b69a9fbf9",
                        "label": "PDE Chart of Accounts for PA Local Educational Agencies, 2024-2025 FY",
                        "url": "https://www.pa.gov/content/dam/copapwp-pagov/en/education/documents/schools/grants-and-funding/school-finances/comptrollers-office/chart%20of%20accounts%202024-2025.pdf",
                        "page_url": "https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/summary-of-annual-financial-report-data/afr-data-detailed",
                        "period": "2024-2025",
                        "sha256": "4a1465e65b28601cc6cc869c017775dd4073eab80face7c9494bdbbf2065405c",
                        "downloaded_at": null
                    }
                ],
                "field_sources": {
                    "category": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "account_code": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "fiscal_year": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "aun": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "district_name": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "county": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "account_name": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "parent_code": [
                        "67442af790cfd54d6fd539ea1608c0a86db38e950ac2c5d7de21df9b69a9fbf9"
                    ],
                    "budget": [
                        "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
                    ],
                    "actual": []
                }
            }
        }
    ],
    "provenance": {
        "sources": [
            {
                "id": "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982",
                "label": "Synthetic gfb workbook",
                "url": "https://fixtures.example/gfb.xlsx",
                "page_url": "https://fixtures.example/reports/gfb",
                "period": "2024-2025",
                "sha256": null,
                "downloaded_at": null
            },
            {
                "id": "67442af790cfd54d6fd539ea1608c0a86db38e950ac2c5d7de21df9b69a9fbf9",
                "label": "PDE Chart of Accounts for PA Local Educational Agencies, 2024-2025 FY",
                "url": "https://www.pa.gov/content/dam/copapwp-pagov/en/education/documents/schools/grants-and-funding/school-finances/comptrollers-office/chart%20of%20accounts%202024-2025.pdf",
                "page_url": "https://www.pa.gov/agencies/education/programs-and-services/schools/grants-and-funding/school-finances/financial-data/summary-of-annual-financial-report-data/afr-data-detailed",
                "period": "2024-2025",
                "sha256": "4a1465e65b28601cc6cc869c017775dd4073eab80face7c9494bdbbf2065405c",
                "downloaded_at": null
            }
        ],
        "field_sources": {
            "aun": [
                "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
            ],
            "district_name": [
                "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
            ],
            "county": [
                "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
            ],
            "fiscal_year": [
                "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982"
            ],
            "accounts": [
                "cbabe049705894bc1e991279dc38ebf78130c6e7f790359d55a8bda357bfd982",
                "67442af790cfd54d6fd539ea1608c0a86db38e950ac2c5d7de21df9b69a9fbf9"
            ],
            "fund_balance": [],
            "indebtedness": [],
            "real_estate_tax_rates": [],
            "selected_data": [],
            "act_one_index": []
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
- `fiscalYear` → `fiscal_year` (`string`): Canonical fiscal year, YYYY-YYYY.
- `fundBalance` → `fund_balance` (`FundBalanceRecord|null`): Optional nested FundBalanceRecord.
- `indebtedness` → `indebtedness` (`Collection|null`): Optional collection of IndebtednessRecord objects.
- `realEstateTaxRates` → `real_estate_tax_rates` (`Collection|null`): Optional collection of county/notes tax-rate records.
- `selectedData` → `selected_data` (`SelectedDataRecord|null`): Optional nested SelectedDataRecord.
- `actOneIndex` → `act_one_index` (`ActOneIndexRecord|null`): Optional nested ActOneIndexRecord.
- `accounts` → `accounts` (`Collection`): Collection of FinancialRecord objects for the selected year/accounts.

Nested `FinancialRecord` fields:

- `aun` → `aun`: Nine-digit administrative unit identifier, stored as a string.
- `districtName` → `district_name`: Published district/LEA name.
- `county` → `county`: Published county; a tax-rate record can represent one of several counties.
- `fiscalYear` → `fiscal_year`: Canonical fiscal year, YYYY-YYYY.
- `category` → `category`: FinancialCategory enum; JSON contains revenue, expenditure, or fund_balance.
- `accountCode` → `account_code`: Published or synthesized account/function code.
- `accountName` → `account_name`: Published account label or chart-of-accounts name.
- `parentCode` → `parent_code`: Chart-of-accounts parent code, when recognized.
- `budget` → `budget`: Budget USD amount; not_requested when budgets were excluded.
- `actual` → `actual`: Actual USD amount; not_requested when actuals were excluded.

## Historical and missing data

A field is not_collected only when the recognized report schema establishes that it was not provided. Blank cells, suppressed values, and unrequested datasets remain nullable. Older supported layouts can omit fields; there is no unverified universal start-year claim on this page. Missing required structure still raises a parsing error.

```php
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

config(['pde-client.missing_data' => 'throw']);
$datum = $query->records()->first();
if ($datum !== null && $datum->hasData('budget')) {
    $value = $datum->budget;
}

try {
    $value = $datum?->budget;
    $data = $datum?->toArray(); // Included uncollected fields also throw.
} catch (DataNotCollectedException $exception) {
    $context = $exception->context; // Dataset, year, district, and source.
    $field = $exception->field;
}
// The catch applies when a recognized report proves noncollection.

config(['pde-client.missing_data' => 'return_null']);
$datum = $query->records()->first(); // Fetch a new DTO after changing configuration.
$value = $datum?->budget; // null if unavailable; genuine zero remains zero.
$data = $datum?->toArray(); // Included uncollected fields serialize as null.
```

toArray()/toJson() follow the same policy. Use only()/except() to export available fields. A completely absent demographic yields no invented row in null mode: get() is empty, first() is null, and sole() still throws. Operational failures are errors in both modes.

See [missing-data semantics](../missing-data.md), [query/year selection](../queries.md), [configuration](../configuration.md), and [raw file discovery](../files.md).
