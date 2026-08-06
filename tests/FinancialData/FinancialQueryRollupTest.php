<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\FinancialQuery;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * Deeper rollUp() edge cases against a small, fully synthetic chart of
 * accounts built just for these tests - FinancialQueryTest already covers
 * the common cascade/regression scenarios against the REAL bundled chart of
 * accounts (6000->6100->6110->6111/6112, and the 9900 catch-all fix); this
 * file isolates boundary conditions that are awkward to construct precisely
 * against the real ~3000-code file: an explicit-zero code with nonzero
 * children, an explicit-zero leaf with no children, a code with neither its
 * own data nor any present children, and an orphan code absent from the
 * tree entirely.
 */
#[AllowMockObjectsWithoutExpectations]
class FinancialQueryRollupTest extends TestCase
{
    private const AUN = '124157203';

    private string $resourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resourcePath = sys_get_temp_dir().'/pde-client-rollup-tests-'.uniqid();
        mkdir($this->resourcePath);

        // 7000 -> 7100 (both absent from raw - neither should surface)
        // 8000 (leaf, no children)
        // 9000 -> 9100 (9000 itself explicitly reported as zero)
        // Deliberately no expenditure.json/fund_balance.json - these tests
        // only ever touch the revenue category.
        file_put_contents($this->resourcePath.'/revenue.json', json_encode([
            ['code' => '7000', 'parent_code' => null, 'title' => 'Unreported Branch', 'is_active' => true],
            ['code' => '7100', 'parent_code' => '7000', 'title' => 'Unreported Leaf', 'is_active' => true],
            ['code' => '8000', 'parent_code' => null, 'title' => 'Explicit Zero Leaf', 'is_active' => true],
            ['code' => '9000', 'parent_code' => null, 'title' => 'Zero Parent With Data Below', 'is_active' => true],
            ['code' => '9100', 'parent_code' => '9000', 'title' => 'Nonzero Child', 'is_active' => true],
        ]));
    }

    public function test_a_code_with_no_own_data_and_no_present_children_is_absent_from_the_result(): void
    {
        $summary = $this->makeQuery(['9000' => 0.0, '9100' => 75.0])
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->get();

        $codes = $summary->accounts->pluck('accountCode')->all();

        $this->assertNotContains('7000', $codes);
        $this->assertNotContains('7100', $codes);
    }

    public function test_an_explicit_zero_leaf_with_no_children_is_kept_not_dropped(): void
    {
        $result = $this->makeQuery(['8000' => 0.0])
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->account('8000')->sole()
            ->accounts->sole();

        $this->assertSame(0.0, $result->budget);
    }

    public function test_an_explicitly_zero_parent_still_rolls_up_from_its_children(): void
    {
        // 9000 is present in raw as exactly 0.0 - not omitted, just zero -
        // so the "own nonzero value wins" branch must NOT fire, and the
        // sum-of-children branch must take over instead.
        $result = $this->makeQuery(['9000' => 0.0, '9100' => 75.0])
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->account('9000')->sole()
            ->accounts->sole();

        $this->assertSame(75.0, $result->budget);
    }

    public function test_an_orphan_code_absent_from_the_tree_passes_through_with_no_parent(): void
    {
        $result = $this->makeQuery(['5555' => 42.0])
            ->district(self::AUN)->year('2024-2025')->budget()->revenues()->account('5555')->sole()
            ->accounts->sole();

        $this->assertSame(42.0, $result->budget);
        $this->assertNull($result->parentCode);
        $this->assertNull($result->parent());
    }

    /**
     * @param  array<string, float>  $raw
     */
    private function makeQuery(array $raw): FinancialQuery
    {
        $repository = $this->createMock(FinancialDataRepository::class);
        $repository->method('availableBudgetYears')->willReturn([FiscalYear::parse('2024-2025')]);
        $repository->method('availableActualYears')->willReturn([]);

        $repository->method('budgetRevenues')->willReturn(new YearTable(
            [self::AUN => ['name' => 'Phoenixville Area SD', 'county' => 'Chester']],
            [self::AUN => $raw],
            [],
        ));

        $chartOfAccounts = new ChartOfAccounts($this->resourcePath);

        return new FinancialQuery(
            $repository,
            $chartOfAccounts,
            $this->app,
            $this->createMock(FinancialDataElementsRepository::class),
            $this->createMock(ActOneIndexRepository::class),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->resourcePath.'/revenue.json');
        @rmdir($this->resourcePath);

        parent::tearDown();
    }
}
