<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData\ChartOfAccounts;

use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\AccountCodeTree;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;

/**
 * ChartOfAccounts resolves a FinancialCategory to the right bundled JSON
 * resource file and memoizes the resulting AccountCodeTree - tested here
 * against a hand-built temp resource directory rather than the real bundled
 * files, so each category's file-name mapping and the memoization behavior
 * are both fully controlled and independent of the real chart of accounts'
 * content (FinancialQueryTest exercises treeFor() against the real files).
 */
class ChartOfAccountsTest extends TestCase
{
    private string $resourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resourcePath = sys_get_temp_dir().'/pde-client-coa-tests-'.uniqid();
        mkdir($this->resourcePath);

        file_put_contents($this->resourcePath.'/revenue.json', json_encode([
            ['code' => '6000', 'parent_code' => null, 'title' => 'REVENUE', 'is_active' => true],
        ]));

        file_put_contents($this->resourcePath.'/expenditure.json', json_encode([
            ['code' => '1000', 'parent_code' => null, 'title' => 'INSTRUCTION', 'is_active' => true],
        ]));

        file_put_contents($this->resourcePath.'/fund_balance.json', json_encode([
            ['code' => '0810', 'parent_code' => null, 'title' => 'RESERVE FOR ENCUMBRANCES', 'is_active' => true],
        ]));
    }

    public function test_tree_for_resolves_each_category_to_its_own_file(): void
    {
        $chartOfAccounts = new ChartOfAccounts($this->resourcePath);

        $this->assertTrue($chartOfAccounts->treeFor(FinancialCategory::Revenue)->exists('6000'));
        $this->assertTrue($chartOfAccounts->treeFor(FinancialCategory::Expenditure)->exists('1000'));
        $this->assertTrue($chartOfAccounts->treeFor(FinancialCategory::FundBalance)->exists('0810'));

        // Each category's own codes don't leak into the others' trees.
        $this->assertFalse($chartOfAccounts->treeFor(FinancialCategory::Revenue)->exists('1000'));
    }

    public function test_tree_for_memoizes_so_the_resource_file_is_only_loaded_once(): void
    {
        $chartOfAccounts = new ChartOfAccounts($this->resourcePath);

        $first = $chartOfAccounts->treeFor(FinancialCategory::Revenue);

        // Deleting the backing file proves the second call never re-reads
        // it - if it weren't memoized, this would throw.
        unlink($this->resourcePath.'/revenue.json');

        $second = $chartOfAccounts->treeFor(FinancialCategory::Revenue);

        $this->assertSame($first, $second);
    }

    public function test_tree_for_throws_when_the_resource_file_is_missing(): void
    {
        $chartOfAccounts = new ChartOfAccounts(sys_get_temp_dir().'/pde-client-coa-tests-nonexistent-'.uniqid());

        $this->expectException(PDEClientException::class);

        $chartOfAccounts->treeFor(FinancialCategory::Revenue);
    }

    public function test_a_trailing_slash_on_the_resource_path_does_not_break_resolution(): void
    {
        $chartOfAccounts = new ChartOfAccounts($this->resourcePath.'/');

        $this->assertTrue($chartOfAccounts->treeFor(FinancialCategory::Revenue)->exists('6000'));
    }

    public function test_the_real_bundled_resource_files_load_successfully(): void
    {
        // A cheap end-to-end sanity check that the actual shipped JSON under
        // resources/chart-of-accounts/ is well-formed and matches the shape
        // AccountCodeTree expects - catches a corrupted/malformed resource
        // file that a hand-built fixture test never would.
        $chartOfAccounts = new ChartOfAccounts(realpath(__DIR__.'/../../../resources/chart-of-accounts'));

        $revenue = $chartOfAccounts->treeFor(FinancialCategory::Revenue);
        $this->assertInstanceOf(AccountCodeTree::class, $revenue);
        $this->assertTrue($revenue->exists('6111'));
        $this->assertSame('6110', $revenue->parentOf('6111'));

        $this->assertTrue($chartOfAccounts->treeFor(FinancialCategory::Expenditure)->exists('1110'));
        $this->assertTrue($chartOfAccounts->treeFor(FinancialCategory::FundBalance)->exists('0810'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->resourcePath.'/*') as $file) {
            @unlink($file);
        }

        @rmdir($this->resourcePath);

        parent::tearDown();
    }
}
