# Historical and missing data

[Documentation index](index.md)

A nullable value alone cannot explain why information is unavailable. The package keeps values and availability separately, through parsing, caching, DTO construction, and aggregation.

- available: a reported value, including an explicit zero.
- not_collected: a recognized report schema omits this field/group. Reading it throws by default. This describes the public report layout, not a claim about every source PDE may have collected internally.
- suppressed: known privacy markers such as * or <11; the numeric value is null.
- not_reported: a blank published cell or an unavailable matching record.
- not_requested: the query excluded this dataset/measure.
- not_applicable: this field does not apply, such as graduate counts on demographic rate rows.
- unknown: an unavailable value whose reason cannot be established.

## Read safely

```php
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;

$record = PDE::district('124157203')->year('2015-2016')->enrollments()->adm()->first();
if ($record !== null) {
    $reason = $record->availability('nonresidentAdm');
    if ($record->hasData('nonresidentAdm')) {
        $value = $record->nonresidentAdm;
    }
    // A direct read throws DataNotCollectedException if the report omitted this column.
}
```

The year above illustrates a query; it is not an asserted universal cutoff. ADM and staff fixtures demonstrate omitted optional metrics. Availability is determined from the report that was actually parsed. Unknown property names are programming errors.

Strict behavior covers direct property reads, isset(), and null coalescing. Use hasData()/availability() for nonthrowing inspection. The exception identifies the field or demographic, dataset, district, year, and source when known. Construction succeeds so available fields in historical reports remain usable.

```php
config(['pde-client.missing_data' => 'return_null']);
$record = PDE::district('124157203')->year('2015-2016')->enrollments()->adm()->first();
$value = $record?->nonresidentAdm; // null if not collected; reported zero stays zero
```

Suppressed, blank, unrequested, inapplicable, and unknown values remain nullable under both modes. Connection, write, and parsing failures remain errors under both modes. No configuration turns a corrupt workbook into valid missing data.

## Export selected fields

Spatie exports (toArray(), all(), toJson(), json_encode(), nested DTOs, and collections) follow the same policy. A full historical export throws if it includes an uncollected field. Select PHP property names in only()/except(); output keys are snake_case.

```php
$safe = $record->only('aun', 'schoolYear', 'adm')->toArray();
$partial = $record->except('nonresidentAdm', 'totalAdmPde363', 'specialEducationAdm')->toArray();
```

MetricMap preserves literal keys and supports immutable array access, get(), availability(), hasData(), and toArray(). Recognized unavailable keys obey the same policy; unknown keys throw InvalidArgumentException. Nested projections work, for example except('breakdown.New metric').

Native PHP object caching preserves DTO availability and its captured policy and restores lazy guards when the object is read again. Public payloads should use the Spatie exports above. Bare JSON has no availability provenance; supply AvailabilityMetadata explicitly when hydrating a DTO if you need to retain the original reasons. Package parsed caches store facts as plain arrays, never policy-dependent DTO results.

## Demographics

A recognized wide cohort header establishes its published demographic columns. Explicitly requesting an absent column throws in strict mode; return_null yields no row (get() empty, first() null). sole() retains its exact-one-result requirement. Unfiltered queries return the groups that are published.

Assessment groups are rows. A group absent from one district’s rows does not prove that the report never collected it. Strict historical errors require complete, verified schema metadata; otherwise the normal empty-match behavior applies. Numeric zero and suppressed rows remain distinguishable from completely absent groups.

Derived totals require complete relevant inputs. They never substitute zero for a known missing component. Summary enrollment metrics use rows actually published by that dataset, rather than grade rows borrowed only from a sibling dataset.
