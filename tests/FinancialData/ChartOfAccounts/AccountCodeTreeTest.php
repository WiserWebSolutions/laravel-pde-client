<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData\ChartOfAccounts;

use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\AccountCodeTree;

/**
 * AccountCodeTree is a pure value object - constructed directly from rows,
 * no filesystem/parser involvement - so every case here is a plain unit
 * test with a small hand-built tree, independent of the real bundled
 * chart-of-accounts JSON (see ChartOfAccountsTest for that resource-loading
 * layer, and FinancialQueryTest for rollUp() exercising the real files).
 */
class AccountCodeTreeTest extends TestCase
{
    /**
     * A 3-level tree with siblings at every level:
     * 6000 -> 6100 -> {6110 -> {6111, 6112}, 6120}
     */
    private function tree(): AccountCodeTree
    {
        return new AccountCodeTree([
            ['code' => '6000', 'parent_code' => null, 'title' => 'REVENUE FROM LOCAL SOURCES', 'is_active' => true],
            ['code' => '6100', 'parent_code' => '6000', 'title' => 'TAXES', 'is_active' => true],
            ['code' => '6110', 'parent_code' => '6100', 'title' => 'AD VALOREM TAXES', 'is_active' => true],
            ['code' => '6111', 'parent_code' => '6110', 'title' => 'Current Real Estate Taxes', 'is_active' => true],
            ['code' => '6112', 'parent_code' => '6110', 'title' => 'Interim Real Estate Taxes', 'is_active' => true],
            ['code' => '6120', 'parent_code' => '6100', 'title' => 'Per Capita Taxes', 'is_active' => true],
        ]);
    }

    public function test_exists_is_true_for_known_codes_and_false_for_unknown_ones(): void
    {
        $tree = $this->tree();

        $this->assertTrue($tree->exists('6111'));
        $this->assertFalse($tree->exists('9999'));
    }

    public function test_parent_of_root_is_null(): void
    {
        $this->assertNull($this->tree()->parentOf('6000'));
    }

    public function test_parent_of_a_child_is_its_immediate_parent_not_an_ancestor(): void
    {
        $this->assertSame('6110', $this->tree()->parentOf('6111'));
        $this->assertSame('6100', $this->tree()->parentOf('6110'));
    }

    public function test_parent_of_an_unknown_code_is_null(): void
    {
        $this->assertNull($this->tree()->parentOf('9999'));
    }

    public function test_children_of_returns_every_direct_child_in_declaration_order(): void
    {
        $this->assertSame(['6110', '6120'], $this->tree()->childrenOf('6100'));
        $this->assertSame(['6111', '6112'], $this->tree()->childrenOf('6110'));
    }

    public function test_children_of_a_leaf_is_empty(): void
    {
        $this->assertSame([], $this->tree()->childrenOf('6111'));
    }

    public function test_children_of_an_unknown_code_is_empty(): void
    {
        $this->assertSame([], $this->tree()->childrenOf('9999'));
    }

    public function test_has_children_distinguishes_branch_codes_from_leaves(): void
    {
        $tree = $this->tree();

        $this->assertTrue($tree->hasChildren('6110'));
        $this->assertFalse($tree->hasChildren('6111'));
        $this->assertFalse($tree->hasChildren('9999'));
    }

    public function test_name_of_returns_the_title_or_null_for_an_unknown_code(): void
    {
        $tree = $this->tree();

        $this->assertSame('Current Real Estate Taxes', $tree->nameOf('6111'));
        $this->assertNull($tree->nameOf('9999'));
    }

    public function test_codes_parents_first_orders_every_code_after_its_own_ancestors(): void
    {
        $order = $this->tree()->codesParentsFirst();
        $position = array_flip($order);

        $this->assertCount(6, $order);
        $this->assertArrayHasKey('6000', $position);

        // Every code's position must come after all of its ancestors', for
        // every code in the tree - not just spot-checked ones - since this
        // ordering is what makes reverse-iteration (child-before-parent)
        // safe for rollUp() to fold amounts bottom-up.
        $parents = [
            '6100' => '6000', '6110' => '6100', '6120' => '6100', '6111' => '6110', '6112' => '6110',
        ];

        foreach ($parents as $code => $parent) {
            $this->assertLessThan(
                $position[$code],
                $position[$parent],
                "Expected [{$parent}] to sort before its child [{$code}]."
            );
        }
    }

    public function test_from_resource_file_throws_when_the_file_does_not_exist(): void
    {
        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('not found at');

        AccountCodeTree::fromResourceFile(sys_get_temp_dir().'/pde-client-tests-nonexistent-'.uniqid().'.json');
    }

    public function test_from_resource_file_loads_a_real_json_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pde-coa-').'.json';
        file_put_contents($path, json_encode([
            ['code' => '6000', 'parent_code' => null, 'title' => 'REVENUE FROM LOCAL SOURCES', 'is_active' => true],
            ['code' => '6100', 'parent_code' => '6000', 'title' => 'TAXES', 'is_active' => true],
        ]));

        $tree = AccountCodeTree::fromResourceFile($path);

        $this->assertTrue($tree->exists('6100'));
        $this->assertSame('6000', $tree->parentOf('6100'));
        $this->assertSame(['6100'], $tree->childrenOf('6000'));
    }
}
