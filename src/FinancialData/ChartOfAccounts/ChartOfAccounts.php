<?php

namespace WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts;

use WiserWebSolutions\PDEClient\Enums\FinancialCategory;

/**
 * Resolves the right AccountCodeTree for a FinancialCategory,
 * loading each bundled resource file at most once per instance.
 */
class ChartOfAccounts
{
    private const RESOURCE_FILES = [
        FinancialCategory::Revenue->value => 'revenue.json',
        FinancialCategory::Expenditure->value => 'expenditure.json',
        FinancialCategory::FundBalance->value => 'fund_balance.json',
    ];

    /** @var array<string, AccountCodeTree> */
    private array $trees = [];

    public function __construct(private readonly string $resourcePath)
    {
    }

    public function treeFor(FinancialCategory $category): AccountCodeTree
    {
        return $this->trees[$category->value] ??= AccountCodeTree::fromResourceFile(
            rtrim($this->resourcePath, '/').'/'.self::RESOURCE_FILES[$category->value]
        );
    }
}
