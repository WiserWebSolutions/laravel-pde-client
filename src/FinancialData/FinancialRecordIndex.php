<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use Illuminate\Support\Collection;

/** One shared relationship index per district/year record set. */
final class FinancialRecordIndex
{
    private array $records = [];

    private array $children = [];

    public function __construct(Collection $records)
    {
        foreach ($records as $record) {
            $this->records[$record->category->value.'|'.$record->accountCode] = $record;
            if ($record->parentCode !== null) {
                $this->children[$record->category->value.'|'.$record->parentCode][] = $record;
            }
        }
    }

    public function parent(FinancialRecord $record): ?FinancialRecord
    {
        return $record->parentCode === null ? null : ($this->records[$record->category->value.'|'.$record->parentCode] ?? null);
    }

    public function children(FinancialRecord $record): Collection
    {
        return collect($this->children[$record->category->value.'|'.$record->accountCode] ?? []);
    }
}
