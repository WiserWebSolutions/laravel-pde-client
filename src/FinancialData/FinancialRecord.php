<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\SourcedValue;

/**
 * One account line for one LEA in one fiscal year, carrying the budgeted
 * amount (from the GFB), the actual amount (from the AFR), or both when the
 * query loaded both measures.
 *
 * `accountCode` is a PA Chart of Accounts dimension code: a revenue code
 * (6111, 7110, 9110, ...) for FinancialCategory::Revenue, an expenditure
 * function code (1110, 2500, ...) for FinancialCategory::Expenditure, or a
 * fund balance code (0810, ...) for FinancialCategory::FundBalance.
 *
 * Published account rollups are preserved when present. Missing rollups are
 * derived from complete children without counting ancestors twice in totals.
 * parent()/children() use one shared index for the district and fiscal year.
 */
#[MapName(SnakeCaseMapper::class)]
final class FinancialRecord extends Data
{
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $fiscalYear;

    public readonly FinancialCategory $category;

    public readonly string $accountCode;

    public readonly ?string $accountName;

    public readonly ?string $parentCode;

    public readonly ?float $budget;

    public readonly ?float $actual;

    /** Shared relationship index, excluded from data exports. */
    private ?FinancialRecordIndex $recordIndex = null;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $fiscalYear,
        FinancialCategory $category,
        string $accountCode,
        ?string $accountName,
        ?string $parentCode,
        ?float $budget,
        ?float $actual,
        ?AvailabilityMetadata $availability = null,
        ?DataProvenance $provenance = null,
    ) {
        $values = compact('aun', 'districtName', 'county', 'fiscalYear', 'category', 'accountCode', 'accountName', 'parentCode', 'budget', 'actual');
        $this->provenance = DataProvenance::forValues($values, $provenance ?? $availability?->provenance);
        $this->initializeData($values, $availability);
        $this->aun = $aun;
        if ($this->availability('districtName') === DataAvailability::NotCollected) {
            $this->markUnavailable('districtName');
        } else {
            $this->districtName = $districtName;
        }
        if ($this->availability('county') === DataAvailability::NotCollected) {
            $this->markUnavailable('county');
        } else {
            $this->county = $county;
        }
        $this->fiscalYear = $fiscalYear;
        $this->category = $category;
        $this->accountCode = $accountCode;
        if ($this->availability('accountName') === DataAvailability::NotCollected) {
            $this->markUnavailable('accountName');
        } else {
            $this->accountName = $accountName;
        }
        if ($this->availability('parentCode') === DataAvailability::NotCollected) {
            $this->markUnavailable('parentCode');
        } else {
            $this->parentCode = $parentCode;
        }
        if ($this->availability('budget') === DataAvailability::NotCollected) {
            $this->markUnavailable('budget');
        } else {
            $this->budget = $budget;
        }
        if ($this->availability('actual') === DataAvailability::NotCollected) {
            $this->markUnavailable('actual');
        } else {
            $this->actual = $actual;
        }
    }

    /** The explicitly selected financial measure, in dollars. */
    public function amount(Measure $measure): ?float
    {
        return $this->{$measure->value};
    }

    public function amountWithSources(Measure $measure): SourcedValue
    {
        return new SourcedValue($this->amount($measure), DataProvenance::forFields($this->sourcesFor($measure->value), ['value']));
    }

    public function varianceWithSources(): SourcedValue
    {
        return new SourcedValue($this->variance(), DataProvenance::forFields([
            ...$this->sourcesFor('budget'), ...$this->sourcesFor('actual'),
        ], ['value']));
    }

    /** Actual minus budget; null unless both measures are present. */
    public function variance(): ?float
    {
        if ($this->actual === null || $this->budget === null) {
            return null;
        }

        return round($this->actual - $this->budget, 2);
    }

    /**
     * The next level up in the chart of accounts (e.g. 6111 -> 6110), or
     * null for a top-level code or when this record wasn't produced by a
     * query (siblings not attached).
     */
    public function parent(): ?self
    {
        return $this->recordIndex?->parent($this);
    }

    /**
     * The accounts rolled up into this one (e.g. 6100's children include
     * 6110, 6120, ...). Empty for a leaf code or when this record wasn't
     * produced by a query (siblings not attached).
     *
     * @return Collection<int, self>
     */
    public function children(): Collection
    {
        return $this->recordIndex?->children($this) ?? collect();
    }

    public function isLeaf(): bool
    {
        return $this->children()->isEmpty();
    }

    /** @internal Attached after the district/year records are mapped. */
    public function attachIndex(FinancialRecordIndex $index): void
    {
        $this->recordIndex = $index;
    }

    /** @param Collection<int, self> $siblings */
    public function attachSiblings(Collection $siblings): void
    {
        $this->recordIndex = new FinancialRecordIndex($siblings);
    }
}
