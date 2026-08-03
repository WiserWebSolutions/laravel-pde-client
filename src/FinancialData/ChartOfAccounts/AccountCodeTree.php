<?php

namespace WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;

/**
 * The parent/child hierarchy for one account-code dimension (revenue,
 * expenditure function, or fund balance), loaded from a bundled PA Chart of
 * Accounts resource file - see resources/chart-of-accounts/*.json, trimmed
 * from the fuller reference data built for the companion chart_of_accounts
 * migration/seeder (code, parent_code, title only; no database involved).
 *
 * This is what lets FinancialRecord::parent()/children() work, and what
 * FinancialQuery uses to roll amounts up to codes PDE's own workbooks never
 * report directly (GFB budgets are leaf-only - see its class docblock).
 */
final class AccountCodeTree
{
    /** @var array<string, ?string> code => parent code */
    private array $parents = [];

    /** @var array<string, list<string>> code => child codes */
    private array $children = [];

    /** @var array<string, string> code => title */
    private array $titles = [];

    public static function fromResourceFile(string $path): self
    {
        if (! is_file($path)) {
            throw new PDEClientException("Chart of accounts resource not found at [{$path}].");
        }

        $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return new self($rows);
    }

    /**
     * @param  list<array{code: string, parent_code: ?string, title: string, is_active: bool}>  $rows
     */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $code = $row['code'];
            $this->parents[$code] = $row['parent_code'];
            $this->titles[$code] = $row['title'];

            if ($row['parent_code'] !== null) {
                $this->children[$row['parent_code']][] = $code;
            }
        }
    }

    public function exists(string $code): bool
    {
        return array_key_exists($code, $this->parents);
    }

    public function parentOf(string $code): ?string
    {
        return $this->parents[$code] ?? null;
    }

    /**
     * @return list<string>
     */
    public function childrenOf(string $code): array
    {
        return $this->children[$code] ?? [];
    }

    public function hasChildren(string $code): bool
    {
        return isset($this->children[$code]);
    }

    public function nameOf(string $code): ?string
    {
        return $this->titles[$code] ?? null;
    }

    /**
     * All codes in this dimension, ordered so that every code appears after
     * its own parent - i.e. safe to fold amounts bottom-up (children first)
     * by processing in *reverse* of this order.
     *
     * @return list<string>
     */
    public function codesParentsFirst(): array
    {
        $ordered = [];
        $visited = [];

        $visit = function (string $code) use (&$visit, &$ordered, &$visited): void {
            if (isset($visited[$code])) {
                return;
            }

            $visited[$code] = true;
            $parent = $this->parents[$code] ?? null;

            if ($parent !== null && $this->exists($parent)) {
                $visit($parent);
            }

            $ordered[] = $code;
        };

        foreach (array_keys($this->parents) as $code) {
            $visit($code);
        }

        return $ordered;
    }
}
