<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use BackedEnum;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use WiserWebSolutions\PDEClient\Assessment\AssessmentRecord;
use WiserWebSolutions\PDEClient\Enrollment\EconomicallyDisadvantagedRecord;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentRecord;
use WiserWebSolutions\PDEClient\FinancialData\FinancialRecord;
use WiserWebSolutions\PDEClient\FinancialData\FundBalanceRecord;
use WiserWebSolutions\PDEClient\FinancialData\IndebtednessRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\AdmRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\RealEstateTaxRateRecord;
use WiserWebSolutions\PDEClient\FinancialDataElements\SelectedDataRecord;
use WiserWebSolutions\PDEClient\Graduation\DropoutRecord;
use WiserWebSolutions\PDEClient\Graduation\GraduationRecord;
use WiserWebSolutions\PDEClient\Personnel\PersonnelRecord;

final class DatasetCatalog
{
    /** @var array<string, array{0: class-string, 1: string, 2: string, 3: list<string>}> */
    private const DATASETS = [
        'gfb-budgets' => [FinancialRecord::class, 'GFB budget account amounts', 'financial_data.gfb', ['category', 'accounts']],
        'afr-actuals' => [FinancialRecord::class, 'AFR actual account amounts', 'financial_data.afr', ['category', 'accounts']],
        'fund-balance' => [FundBalanceRecord::class, 'Reported general fund balances', 'financial_data.afr', []],
        'indebtedness' => [IndebtednessRecord::class, 'Debt by fund type and reporting phase', 'financial_data.afr', ['fund_types', 'phases']],
        'real-estate-tax-rates' => [RealEstateTaxRateRecord::class, 'County-specific district tax rates', 'financial_data_elements', ['county', 'notes']],
        'selected-data' => [SelectedDataRecord::class, 'Financial indicators and published rankings', 'financial_data_elements', []],
        'act-one-index' => [ActOneIndexRecord::class, 'Act 1 adjusted index', 'act_one_index', []],
        'public-enrollment' => [EnrollmentRecord::class, 'Published enrollment by grade', 'enrollment', ['grades']],
        'enrollment-projections' => [EnrollmentRecord::class, 'PDE enrollment projections by grade', 'enrollment', ['grades']],
        'english-learners' => [EnrollmentRecord::class, 'English learner enrollment by grade', 'enrollment', ['grades']],
        'economically-disadvantaged' => [EconomicallyDisadvantagedRecord::class, 'Economically disadvantaged enrollment', 'enrollment', []],
        'adm' => [AdmRecord::class, 'Average Daily Membership and weighted membership', 'financial_data_elements', []],
        'pssa' => [AssessmentRecord::class, 'PSSA proficiency and scored counts', 'assessment', ['subjects', 'grades', 'groups']],
        'keystone' => [AssessmentRecord::class, 'Keystone proficiency and scored counts', 'assessment', ['subjects', 'grades', 'groups']],
        'cohort-graduation' => [GraduationRecord::class, 'Graduation within a four, five, or six year cohort', 'graduation', ['cohort_years', 'groups']],
        'dropouts' => [DropoutRecord::class, 'Annual dropout counts and rates', 'graduation', []],
        'professional-personnel' => [PersonnelRecord::class, 'Professional personnel counts and averages', 'personnel', ['personnel_categories']],
    ];

    public function all(): array
    {
        return array_map(fn ($id) => $this->get($id), array_keys(self::DATASETS));
    }

    public function ids(): array
    {
        return array_keys(self::DATASETS);
    }

    public function get(string $id): array
    {
        [$class, $description, $source, $filters] = self::DATASETS[$id]
            ?? throw new ToolException('unknown_dataset', "Unknown dataset [{$id}]. Use list_datasets.");
        $fields = [];
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getName() === 'provenance') {
                continue;
            }
            $key = Str::snake($property->getName());
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
            $fields[$key] = [
                'type' => is_subclass_of($typeName, BackedEnum::class) ? 'enum' : $typeName,
                'nullable' => $type?->allowsNull() ?? true,
                'unit' => $this->unit($id, $key, $typeName),
                'description' => Str::headline($key),
            ];
            if (is_subclass_of($typeName, BackedEnum::class)) {
                $fields[$key]['values'] = array_column($typeName::cases(), 'value');
            }
        }

        return [
            'id' => $id, 'description' => $description, 'fields' => $fields, 'filters' => $filters,
            'source_page' => config('pde-client.'.$source.'.page_url'),
            'year_convention' => 'YYYY-YYYY, starting school/fiscal year; assessment 2024-2025 means spring 2025',
            'notes' => 'Flat records. Availability and demographic coverage follow the parsed report, not an assumed historical cutoff.',
        ];
    }

    public function availableYears(string $id, array $filters = []): array
    {
        $this->get($id);

        return array_map(fn ($year) => $year->long(), app(DatasetAccess::class)->years($id, $filters));
    }

    public function units(string $id): array
    {
        return array_filter(array_map(fn ($field) => $field['unit'], $this->get($id)['fields']), fn ($unit) => $unit !== null);
    }

    private function unit(string $id, string $field, string $type): ?string
    {
        if ($type !== 'float' && ! str_ends_with($type, 'MetricMap')) {
            return null;
        }
        if (str_ends_with($field, '_rank')) {
            return 'rank';
        }
        if (str_starts_with($field, 'percent_')) {
            return 'percent';
        }
        if (in_array($field, ['rate', 'index', 'aid_ratio'], true)) {
            return 'fraction';
        }
        if (in_array($field, ['adjustment_factor', 'average_education_level'], true)) {
            return 'index';
        }
        if (str_contains($field, 'mills')) {
            return 'mills';
        }
        if (in_array($id, ['gfb-budgets', 'afr-actuals', 'fund-balance', 'indebtedness'], true) || $field === 'average_salary') {
            return 'USD';
        }
        if (str_contains($field, '_per_wadm')) {
            return 'USD_per_WADM';
        }
        if (str_contains($field, '_per_adm')) {
            return 'USD_per_ADM';
        }
        if ($field === 'population_per_square_mile') {
            return 'persons_per_square_mile';
        }
        if (str_starts_with($field, 'average_years')) {
            return 'years';
        }
        if (in_array($id, ['adm', 'selected-data'], true)) {
            return 'membership';
        }

        return $id === 'professional-personnel' ? 'staff' : 'students';
    }
}
