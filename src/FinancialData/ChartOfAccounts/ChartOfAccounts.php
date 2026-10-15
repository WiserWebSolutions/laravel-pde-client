<?php

namespace WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts;

use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Support\SourceReference;

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

    public function __construct(private readonly string $resourcePath) {}

    public function treeFor(FinancialCategory $category): AccountCodeTree
    {
        return $this->trees[$category->value] ??= AccountCodeTree::fromResourceFile(
            rtrim($this->resourcePath, '/').'/'.self::RESOURCE_FILES[$category->value],
            $this->sourceFor($category),
        );
    }

    private function sourceFor(FinancialCategory $category): ?SourceReference
    {
        $manifest = rtrim($this->resourcePath, '/').'/sources.json';
        if (! is_file($manifest)) {
            return null;
        }
        $data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $path = rtrim($this->resourcePath, '/').'/'.self::RESOURCE_FILES[$category->value];
        if (! is_file($path) || ($data['resource_sha256'][$category->value] ?? null)
            !== hash('sha256', str_replace("\r\n", "\n", file_get_contents($path)))) {
            return null;
        }

        return SourceReference::fromArray($data['source']);
    }
}
