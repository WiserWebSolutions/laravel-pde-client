<?php

namespace WiserWebSolutions\PDEClient\Tests\AgentData;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use WiserWebSolutions\PDEClient\AgentData\DatasetCatalog;
use WiserWebSolutions\PDEClient\AgentData\ToolDefinitions;
use WiserWebSolutions\PDEClient\AgentData\ToolDispatcher;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Tests\Support\DocumentationFixtures;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class AgentToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DocumentationFixtures::register($this->app);
    }

    public static function endpoints(): array
    {
        $definitions = json_decode(file_get_contents(__DIR__.'/../Fixtures/documentation/endpoints.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn ($id) => [$id], array_keys($definitions));
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_has_a_working_operation_and_matching_catalog_fields(string $dataset): void
    {
        $result = $this->executeTool('get_'.str_replace('-', '_', $dataset), ['aun' => DocumentationFixtures::AUN, 'year' => '2024-2025']);
        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertNotEmpty($result['data']);
        $entry = PDE::catalog()->get($dataset);
        foreach ($result['data'] as $row) {
            $this->assertSame(array_keys($entry['fields']), array_keys($row['values']));
            $this->assertSame(array_keys($row['values']), array_keys(array_filter($row['availability'], fn ($key) => ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY)));
            $this->assertSame(DocumentationFixtures::AUN, $row['values']['aun']);
        }
        $this->assertNotEmpty($result['sources']);
        $this->assertSame('2024-2025', $result['context']['year']);
    }

    public function test_catalog_and_observed_schema_are_discoverable_without_invented_cutoffs(): void
    {
        $this->assertCount(17, $this->executeTool('list_datasets')['data']);
        $this->assertCount(24, app(ToolDefinitions::class)->all());
        $description = $this->executeTool('describe_dataset', ['dataset' => 'pssa', 'year' => '2024-2025']);
        $this->assertSame(['2024-2025'], $description['data'][0]['available_years']);
        $this->assertCount(1, $description['data'][0]['observed_schema']);
        $this->assertSame('percent', app(DatasetCatalog::class)->units('pssa')['percent_proficient_or_above']);
        $this->assertSame('fraction', app(DatasetCatalog::class)->units('cohort-graduation')['rate']);
    }

    public static function filteredEndpoints(): array
    {
        return [
            ['gfb-budgets', ['category' => 'revenue', 'accounts' => ['6111']]],
            ['afr-actuals', ['category' => 'expenditure', 'accounts' => ['1110']]],
            ['public-enrollment', ['grades' => ['1']]],
            ['enrollment-projections', ['grades' => ['1']]],
            ['english-learners', ['grades' => ['1']]],
            ['pssa', ['subjects' => ['Math'], 'grades' => ['3'], 'groups' => ['All Students']]],
            ['keystone', ['subjects' => ['Algebra I'], 'grades' => ['11'], 'groups' => ['All Students']]],
            ['cohort-graduation', ['cohort_years' => 5, 'groups' => ['Total']]],
            ['indebtedness', ['fund_types' => ['governmental'], 'phases' => ['end']]],
            ['professional-personnel', ['personnel_categories' => ['classroom_teacher']]],
            ['real-estate-tax-rates', ['county' => 'Fixture County', 'notes' => 'Municipality A']],
        ];
    }

    #[DataProvider('filteredEndpoints')]
    public function test_supported_filters_are_shared_and_do_not_leak(string $dataset, array $filters): void
    {
        $operation = 'get_'.str_replace('-', '_', $dataset);
        $base = ['aun' => DocumentationFixtures::AUN, 'year' => '2024-2025'];
        $filtered = $this->executeTool($operation, $base + $filters);
        $this->assertSame('ok', $filtered['status'], json_encode($filtered));
        $this->assertCount(1, $filtered['data']);
        $this->assertSame('ok', $this->executeTool($operation, $base)['status']);
    }

    public function test_invalid_filters_years_auns_and_limits_are_rejected_before_io(): void
    {
        foreach ([
            ['get_adm', ['aun' => '123']],
            ['get_adm', ['aun' => DocumentationFixtures::AUN, 'year' => 'all']],
            ['get_adm', ['aun' => DocumentationFixtures::AUN, 'grades' => ['1']]],
            ['get_pssa', ['aun' => DocumentationFixtures::AUN, 'groups' => []]],
            ['get_adm', ['aun' => DocumentationFixtures::AUN, 'limit' => 101]],
            ['trend', ['dataset' => 'adm', 'aun' => DocumentationFixtures::AUN, 'metric' => 'adm']],
        ] as [$operation, $arguments]) {
            $result = $this->executeTool($operation, $arguments);
            $this->assertSame('invalid_arguments', $result['error']['code'], json_encode($result));
        }
    }

    public function test_name_search_ranks_exact_matches_and_preserves_leading_zeroes_and_historical_names(): void
    {
        $this->directoryFixture();
        $result = $this->executeTool('find_districts', ['search' => 'Lake']);
        $this->assertTrue($result['context']['ambiguous']);
        $this->assertSame('001234567', $result['data'][0]['aun']);
        $this->assertSame('exact', $result['data'][0]['match']);
        $this->assertSame('SD', $result['data'][0]['lea_type']);
        $historic = $this->executeTool('find_districts', ['search' => 'Old Lake', 'year' => '2023-2024']);
        $this->assertSame('Old Lake', $historic['data'][0]['name']);
        $missing = $this->executeTool('get_public_enrollment', ['aun' => '001234567', 'year' => '2022-2023']);
        $this->assertSame('unavailable', $missing['status']);
    }

    public function test_pagination_is_deterministic_bound_to_the_query_and_detects_changed_sources(): void
    {
        $this->directoryFixture();
        $arguments = ['search' => 'lake', 'limit' => 1];
        $first = $this->executeTool('find_districts', $arguments);
        $this->assertTrue($first['pagination']['has_more']);
        $cursor = $first['pagination']['next_cursor'];
        $next = $this->executeTool('find_districts', $arguments + ['cursor' => $cursor]);
        $this->assertNotSame($first['data'][0]['aun'], $next['data'][0]['aun']);
        $wrong = $this->executeTool('find_districts', ['search' => 'Other', 'limit' => 1, 'cursor' => $cursor]);
        $this->assertSame('invalid_cursor', $wrong['error']['code']);
        $this->directoryFixture('b');
        $changed = $this->executeTool('find_districts', $arguments + ['cursor' => $cursor]);
        $this->assertSame('source_changed', $changed['error']['code']);
    }

    public function test_operational_failures_remain_errors_and_do_not_expose_storage_paths(): void
    {
        $this->app->instance(EnrollmentDataRepository::class, new class extends EnrollmentDataRepository
        {
            public function __construct() {}

            public function availablePublicYears(): array
            {
                throw new \RuntimeException('private filesystem path /secret/workbook.xlsx');
            }
        });
        $result = $this->executeTool('find_districts', ['search' => 'Lake']);
        $this->assertSame('operational_error', $result['error']['code']);
        $this->assertStringNotContainsString('/secret', json_encode($result));
    }

    private function directoryFixture(string $hash = 'a'): void
    {
        $this->app->instance(EnrollmentDataRepository::class, new class($hash) extends EnrollmentDataRepository
        {
            public function __construct(private readonly string $hash) {}

            public function availablePublicYears(): array
            {
                return [FiscalYear::parse(2023), FiscalYear::parse(2024)];
            }

            public function publicTable(FiscalYear $year): YearTable
            {
                if ($year->startYear < 2023) {
                    throw DataSetNotFoundException::noneMatched('year');
                }

                return new YearTable([
                    '001234567' => ['name' => $year->startYear === 2023 ? 'Old Lake' : 'Lake', 'county' => 'North', 'lea_type' => 'SD'],
                    '124157203' => ['name' => 'Lake Shore', 'county' => 'South', 'lea_type' => 'SD'],
                ], ['001234567' => ['1' => 10], '124157203' => ['1' => 20]], ['1' => null], sources: [
                    ['url' => 'https://fixtures.example/enrollment.xlsx', 'sha256' => $this->hash, 'downloaded_at' => '2026-10-14T12:00:00+00:00'],
                ]);
            }
        });
    }

    private function executeTool(string $operation, array $arguments = []): array
    {
        return app(ToolDispatcher::class)->execute($operation, $arguments);
    }
}
