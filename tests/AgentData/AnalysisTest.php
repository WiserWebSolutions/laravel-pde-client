<?php

namespace WiserWebSolutions\PDEClient\Tests\AgentData;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\AgentData\ToolDispatcher;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\GraduationDataRepository;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\Support\DocumentationFixtures;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class AnalysisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DocumentationFixtures::register($this->app);
    }

    public function test_financial_totals_use_existing_parent_aware_rollups_and_variance(): void
    {
        $this->app->instance(FinancialDataRepository::class, new class extends FinancialDataRepository
        {
            public function __construct() {}

            public function budgetRevenues(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['6100' => 1000, '6110' => 1000, '6111' => 1000], 'gfb');
            }

            public function actualRevenues(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['6100' => 1100, '6110' => 1100, '6111' => 1100], 'afr');
            }
        });
        $base = ['aun' => DocumentationFixtures::AUN, 'years' => ['2024-2025'], 'category' => 'revenue'];
        $total = $this->executeTool('calculate_total', ['dataset' => 'gfb-budgets'] + $base);
        $this->assertSame(1000.0, $total['data'][0]['value'], json_encode($total));
        $variance = $this->executeTool('budget_variance', $base);
        $this->assertSame(100.0, $variance['data'][0]['value'], json_encode($variance));
        $this->assertSame(10.0, $variance['data'][0]['percent_change']);
    }

    public function test_totals_keep_enrollment_projections_and_english_learners_separate(): void
    {
        foreach (['public-enrollment' => 100.0, 'enrollment-projections' => 110.0, 'english-learners' => 20.0] as $dataset => $value) {
            $result = $this->executeTool('calculate_total', ['dataset' => $dataset, 'aun' => DocumentationFixtures::AUN, 'years' => ['2024-2025']]);
            $this->assertSame($value, $result['data'][0]['value'], json_encode($result));
        }
    }

    public function test_overlapping_grade_totals_are_rejected(): void
    {
        $this->app->instance(EnrollmentDataRepository::class, new class extends EnrollmentDataRepository
        {
            public function __construct() {}

            public function publicTable(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['1' => 100, 'Total' => 100], 'enrollment');
            }
        });
        $result = $this->executeTool('calculate_total', ['dataset' => 'public-enrollment', 'aun' => DocumentationFixtures::AUN, 'years' => ['2024-2025']]);
        $this->assertSame('overlapping_measurements', $result['error']['code']);
    }

    public function test_missing_requested_components_do_not_silently_reduce_totals(): void
    {
        $enrollment = $this->executeTool('calculate_total', [
            'dataset' => 'public-enrollment', 'aun' => DocumentationFixtures::AUN,
            'years' => ['2024-2025'], 'grades' => ['1', '12'],
        ]);
        $this->assertSame('partial', $enrollment['status']);
        $this->assertNull($enrollment['data'][0]['value']);
        $this->assertSame('not_reported', $enrollment['data'][0]['availability']);
        $financial = $this->executeTool('calculate_total', [
            'dataset' => 'gfb-budgets', 'aun' => DocumentationFixtures::AUN,
            'years' => ['2024-2025'], 'category' => 'revenue', 'accounts' => ['6111', '9999'],
        ]);
        $this->assertSame('partial', $financial['status']);
        $this->assertNull($financial['data'][0]['value']);
    }

    public function test_trends_preserve_native_rates_and_compute_percentage_points(): void
    {
        $this->graduationFixture();
        $result = $this->executeTool('trend', [
            'dataset' => 'cohort-graduation', 'aun' => DocumentationFixtures::AUN,
            'years' => ['2024-2025', '2023-2024'], 'metric' => 'rate', 'groups' => ['Total'],
        ]);
        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertSame(0.9, $result['data'][1]['value']);
        $this->assertSame(10.0, $result['data'][1]['difference']);
        $this->assertSame('percentage_points', $result['data'][1]['difference_unit']);
        $this->assertSame(12.5, $result['data'][1]['percent_change']);
    }

    public function test_district_comparisons_use_the_first_aun_as_baseline_and_require_matching_dimensions(): void
    {
        $this->graduationFixture();
        $base = ['dataset' => 'cohort-graduation', 'auns' => [DocumentationFixtures::AUN, '001234567'],
            'years' => ['2024-2025'], 'metric' => 'rate'];
        $result = $this->executeTool('compare_districts', $base);
        $this->assertSame(-10.0, $result['data'][1]['difference'], json_encode($result));
        $this->assertSame(DocumentationFixtures::AUN, $result['data'][1]['baseline']['aun']);
        $this->graduationFixture(differentGroup: true);
        $mismatch = $this->executeTool('compare_districts', $base);
        $this->assertSame('incompatible_measurements', $mismatch['error']['code']);
    }

    public function test_zero_baseline_and_suppressed_inputs_remain_explicit(): void
    {
        $this->graduationFixture(zeroBaseline: true);
        $arguments = ['dataset' => 'cohort-graduation', 'aun' => DocumentationFixtures::AUN,
            'years' => ['2023-2024', '2024-2025'], 'metric' => 'rate'];
        $result = $this->executeTool('trend', $arguments);
        $this->assertNull($result['data'][1]['percent_change']);
        $this->assertSame('zero_baseline', $result['data'][1]['percent_change_availability']);
        $this->graduationFixture(suppressed: true);
        $result = $this->executeTool('trend', $arguments);
        $this->assertSame('partial', $result['status']);
        $this->assertNull($result['data'][1]['value']);
        $this->assertNull($result['data'][1]['difference']);
        $this->assertSame('suppressed', $result['data'][1]['availability']);
    }

    public function test_ambiguous_rows_invalid_metrics_and_configured_analysis_limits_are_rejected(): void
    {
        $base = ['dataset' => 'gfb-budgets', 'aun' => DocumentationFixtures::AUN, 'years' => ['2023-2024', '2024-2025'], 'metric' => 'budget'];
        $this->assertSame('ambiguous_measurement', $this->executeTool('trend', $base)['error']['code']);
        $base['metric'] = 'district_name';
        $this->assertSame('invalid_metric', $this->executeTool('trend', $base)['error']['code']);
        config(['pde-client.agent.max_years' => 1]);
        $this->assertSame('invalid_arguments', $this->executeTool('trend', $base)['error']['code']);
        $this->assertSame('invalid_arguments', $this->executeTool('compare_districts', [
            'dataset' => 'adm', 'auns' => ['001234567', '101234567', '201234567', '301234567', '401234567', '501234567'],
            'years' => ['2024-2025'], 'metric' => 'adm',
        ])['error']['code']);
    }

    private function graduationFixture(bool $zeroBaseline = false, bool $suppressed = false, bool $differentGroup = false): void
    {
        $this->app->instance(GraduationDataRepository::class, new class($zeroBaseline, $suppressed, $differentGroup) extends GraduationDataRepository
        {
            public function __construct(private bool $zeroBaseline, private bool $suppressed, private bool $differentGroup) {}

            public function cohortTable(CohortSpan $span, FiscalYear $year): RowTable
            {
                $first = $year->startYear === 2023 ? ($this->zeroBaseline ? 0.0 : 0.8) : 0.9;
                $first = $this->suppressed && $year->startYear === 2024 ? null : $first;
                $rows = [
                    DocumentationFixtures::AUN => [['group' => 'Total', 'graduates' => 90.0, 'cohort_size' => 100.0, 'rate' => $first]],
                    '001234567' => [['group' => $this->differentGroup ? 'Female' : 'Total', 'graduates' => 80.0, 'cohort_size' => 100.0, 'rate' => 0.8]],
                ];

                return new RowTable([DocumentationFixtures::AUN => DocumentationFixtures::DISTRICT, '001234567' => DocumentationFixtures::DISTRICT],
                    $rows, cells: $first === null ? [DocumentationFixtures::AUN => [0 => ['rate' => 'suppressed']]] : [],
                    source: 'https://fixtures.example/graduation.xlsx');
            }
        });
    }

    private function executeTool(string $operation, array $arguments): array
    {
        return app(ToolDispatcher::class)->execute($operation, $arguments);
    }
}
