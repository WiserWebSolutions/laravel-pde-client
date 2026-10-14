<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use WiserWebSolutions\PDEClient\Enums\DebtPhase;
use WiserWebSolutions\PDEClient\Enums\FundType;
use WiserWebSolutions\PDEClient\Enums\PersonnelCategory;

/** SDK-neutral schemas are the single source for validation and both adapters. */
final class ToolDefinitions
{
    public function __construct(private readonly DatasetCatalog $catalog) {}

    public function all(): array
    {
        $dataset = $this->string('Dataset identifier', $this->catalog->ids()) + ['required' => true];
        $year = $this->string('School/fiscal year, e.g. 2024-2025. Omit for latest.') + ['format' => 'school_year'];
        $paging = [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('pde-client.agent.page_size', 100), 'description' => 'Maximum rows on this page'],
            'cursor' => $this->string('Continuation cursor from an identical previous query'),
        ];
        $tools = [
            'list_datasets' => $this->definition('List the 17 supported datasets, fields, units, filters and source pages without downloading data.', []),
            'describe_dataset' => $this->definition('Discover available years and, with an explicit year, observed report schema and sources. Historical coverage is source-backed.', ['dataset' => $dataset, 'year' => $year, ...$this->filterProperties()]),
            'find_districts' => $this->definition('Find districts/LEAs by AUN or name in public enrollment. Exact matches rank first. Ask the user to choose ambiguous names.', [
                'search' => $this->string('District name or nine-digit AUN') + ['required' => true],
                'year' => $year, ...$paging,
            ]),
        ];
        foreach ($this->catalog->all() as $entry) {
            $tools['get_'.str_replace('-', '_', $entry['id'])] = $this->definition(
                'Retrieve '.$entry['description'].'. Returns flat records with availability reasons and citations; never infer suppressed values.',
                [
                    'aun' => $this->string('Nine-digit AUN obtained from find_districts') + ['pattern' => '/^\d{9}$/', 'required' => true],
                    'year' => $year,
                    ...$this->filters($entry['id']),
                    'fields' => $this->strings('Select serialized fields', array_keys($entry['fields'])),
                    ...$paging,
                ],
                $entry['id'],
            );
        }
        $analysis = [
            'dataset' => $dataset,
            'years' => [...$this->strings('Explicit school/fiscal years; no latest-year substitution'), 'required' => true, 'maxItems' => (int) config('pde-client.agent.max_years', 10), 'format' => 'school_years'],
            ...$this->filterProperties(),
        ];
        $aun = $this->string('Nine-digit district AUN') + ['pattern' => '/^\d{9}$/', 'required' => true];
        $metric = $this->string('Numeric snake_case field from describe_dataset; preserve its published units') + ['required' => true];
        $tools['calculate_total'] = $this->definition('Calculate parent-aware financial totals or disjoint grade enrollment totals, separately per explicit year. Requires financial category.', [
            ...$analysis, 'aun' => $aun,
        ]);
        $tools['budget_variance'] = $this->definition('Compare the same financial category/accounts and year: actual minus budget. Missing inputs produce unavailable calculations.', [
            'aun' => $aun, 'years' => $analysis['years'],
            'category' => $this->filterProperties()['category'] + ['required' => true],
            'accounts' => $this->filterProperties()['accounts'],
        ]);
        $tools['trend'] = $this->definition('Compare one numeric metric across explicit years. Filters must select exactly one compatible record per year; rates use percentage-point differences.', [
            ...$analysis, 'aun' => $aun, 'metric' => $metric,
        ]);
        $tools['compare_districts'] = $this->definition('Compare the same numeric metric and dimensions across explicit districts and years. The first AUN is the baseline. Resolve ambiguous names first.', [
            ...$analysis,
            'auns' => [...$this->strings('District AUNs; first is baseline'), 'required' => true, 'minItems' => 2, 'maxItems' => (int) config('pde-client.agent.max_districts', 5), 'format' => 'auns'],
            'metric' => $metric,
        ]);

        return $tools;
    }

    public function get(string $name): array
    {
        return $this->all()[$name] ?? throw new ToolException('unknown_tool', "Unknown PDE tool [{$name}].");
    }

    public function filters(string $dataset): array
    {
        return array_intersect_key($this->filterProperties(), array_flip($this->catalog->get($dataset)['filters']));
    }

    private function filterProperties(): array
    {
        return [
            'category' => $this->string('Financial category', ['revenue', 'expenditure', 'fund_balance']),
            'accounts' => $this->strings('Account codes, preserving leading zeroes'),
            'grades' => $this->strings('Grade labels; do not mix Total with individual grades in analysis'),
            'subjects' => $this->strings('Published assessment subjects'),
            'groups' => $this->strings('Published demographic labels; do not combine overlapping groups'),
            'cohort_years' => ['type' => 'integer', 'enum' => [4, 5, 6], 'description' => 'Graduation cohort length; defaults to 4'],
            'fund_types' => $this->strings('Debt fund types', array_column(FundType::cases(), 'value')),
            'phases' => $this->strings('Debt reporting phases', array_column(DebtPhase::cases(), 'value')),
            'personnel_categories' => $this->strings('Published staff categories', array_column(PersonnelCategory::cases(), 'value')),
            'county' => $this->string('Exact county for a tax-rate row'),
            'notes' => $this->string('Exact published notes/municipality for a tax-rate row'),
        ];
    }

    private function string(string $description, ?array $enum = null): array
    {
        return ['type' => 'string', 'description' => $description, ...($enum === null ? [] : ['enum' => $enum])];
    }

    private function strings(string $description, ?array $enum = null): array
    {
        return ['type' => 'array', 'description' => $description, 'items' => $this->string('Value', $enum), 'minItems' => 1, 'maxItems' => 50];
    }

    private function definition(string $description, array $properties, ?string $dataset = null): array
    {
        return ['description' => $description, 'properties' => $properties, 'dataset' => $dataset];
    }
}
