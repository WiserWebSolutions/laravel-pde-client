<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * One account line for one LEA in one fiscal year, carrying the budgeted
 * amount (from the GFB), the actual amount (from the AFR), or both when the
 * query loaded both measures.
 *
 * `accountCode` is a PA Chart of Accounts dimension code: a revenue code
 * (6111, 7110, 9110, ...) for CATEGORY_REVENUE, an expenditure function code
 * (1110, 2500, ...) for CATEGORY_EXPENDITURE, or a fund balance code (0810,
 * ...) for CATEGORY_FUND_BALANCE.
 *
 * Both source datasets are organized around the same parent/child account
 * hierarchy (6000 -> 6100 -> 6110 -> 6111, 1000 -> 1100 -> 1110, ...), and
 * `budget`/`actual` on a parent code already reflect the roll-up: FinancialQuery
 * computes every parent as the sum of its children before building records,
 * rather than trusting the source's own total (GFB in particular never
 * publishes one at all - see GfbWorkbookParser). parent()/children() walk
 * that same hierarchy to move between a record and its siblings.
 */
final class FinancialRecord implements Arrayable
{
    public const CATEGORY_REVENUE = 'revenue';

    public const CATEGORY_EXPENDITURE = 'expenditure';

    public const CATEGORY_FUND_BALANCE = 'fund_balance';

    /**
     * The other records from the same query result, set once by
     * FinancialQuery right after building the full set - this is what makes
     * parent()/children() possible without every record needing its own
     * reference back to the chart of accounts and the query's other results.
     *
     * @var Collection<int, self>|null
     */
    private ?Collection $siblings = null;

    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $fiscalYear,
        public readonly string $category,
        public readonly string $accountCode,
        public readonly ?string $accountName,
        public readonly ?string $parentCode,
        public readonly ?float $budget,
        public readonly ?float $actual,
    ) {
    }

    /**
     * The single number this record represents when only one measure was
     * requested; prefers actual when both are present.
     */
    public function amount(): ?float
    {
        return $this->actual ?? $this->budget;
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
        if ($this->parentCode === null || $this->siblings === null) {
            return null;
        }

        return $this->siblings->first(
            fn (self $record) => $record->category === $this->category && $record->accountCode === $this->parentCode
        );
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
        if ($this->siblings === null) {
            return collect();
        }

        return $this->siblings
            ->filter(fn (self $record) => $record->category === $this->category && $record->parentCode === $this->accountCode)
            ->values();
    }

    public function isLeaf(): bool
    {
        return $this->children()->isEmpty();
    }

    /**
     * @internal set by FinancialQuery::get() once the full result set exists; not part of the public construction API
     *
     * @param  Collection<int, self>  $siblings
     */
    public function attachSiblings(Collection $siblings): void
    {
        $this->siblings = $siblings;
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'county' => $this->county,
            'fiscal_year' => $this->fiscalYear,
            'category' => $this->category,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'parent_code' => $this->parentCode,
            'budget' => $this->budget,
            'actual' => $this->actual,
        ];
    }
}
