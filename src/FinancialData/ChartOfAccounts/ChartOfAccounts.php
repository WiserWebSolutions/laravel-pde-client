<?php

namespace WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\FinancialRecord;

/**
 * Resolves the right AccountCodeTree for a FinancialRecord category,
 * loading each bundled resource file at most once per instance.
 */
class ChartOfAccounts
{
    private const RESOURCE_FILES = [
        FinancialRecord::CATEGORY_REVENUE => 'revenue.json',
        FinancialRecord::CATEGORY_EXPENDITURE => 'expenditure.json',
        FinancialRecord::CATEGORY_FUND_BALANCE => 'fund_balance.json',
    ];

    /** @var array<string, AccountCodeTree> */
    private array $trees = [];

    public function __construct(private readonly string $resourcePath)
    {
    }

    public function treeFor(string $category): AccountCodeTree
    {
        if (! isset(self::RESOURCE_FILES[$category])) {
            throw new PDEClientException("No chart of accounts hierarchy for category [{$category}].");
        }

        return $this->trees[$category] ??= AccountCodeTree::fromResourceFile(
            rtrim($this->resourcePath, '/').'/'.self::RESOURCE_FILES[$category]
        );
    }
}
