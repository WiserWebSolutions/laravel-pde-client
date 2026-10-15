<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use WiserWebSolutions\PDEClient\Assessment\AssessmentDataRepository;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\GraduationDataRepository;
use WiserWebSolutions\PDEClient\PDEClientManager;
use WiserWebSolutions\PDEClient\Personnel\PersonnelDataRepository;
use WiserWebSolutions\PDEClient\Support\RowTable;

/** Maps catalog operations onto existing, independently usable package queries. */
final class DatasetAccess
{
    /** @return list<FiscalYear> newest first */
    public function years(string $id, array $filters = []): array
    {
        $years = match ($id) {
            'gfb-budgets' => app(FinancialDataRepository::class)->availableBudgetYears(),
            'afr-actuals' => app(FinancialDataRepository::class)->availableActualYears(isset($filters['category']) ? FinancialCategory::from($filters['category']) : null),
            'fund-balance' => app(FinancialDataRepository::class)->availableFundBalanceYears(),
            'indebtedness' => app(FinancialDataRepository::class)->availableIndebtednessYears(),
            'public-enrollment' => app(EnrollmentDataRepository::class)->availablePublicYears(),
            'enrollment-projections' => app(EnrollmentDataRepository::class)->availableProjectionYears(),
            'english-learners' => app(EnrollmentDataRepository::class)->availableElYears(),
            'economically-disadvantaged' => app(EnrollmentDataRepository::class)->availableEconomicallyDisadvantagedYears(),
            'adm' => app(FinancialDataElementsRepository::class)->availableAdmYears(),
            'real-estate-tax-rates' => app(FinancialDataElementsRepository::class)->availableTaxRateYears(),
            'selected-data' => app(FinancialDataElementsRepository::class)->availableSelectedDataYears(),
            'act-one-index' => app(ActOneIndexRepository::class)->availableYears(),
            'pssa', 'keystone' => app(AssessmentDataRepository::class)->availableYears($id),
            'cohort-graduation' => app(GraduationDataRepository::class)->availableCohortYears(CohortSpan::from($filters['cohort_years'] ?? 4)),
            'dropouts' => app(GraduationDataRepository::class)->availableDropoutYears(),
            'professional-personnel' => app(PersonnelDataRepository::class)->availableYears(),
            default => throw new ToolException('unknown_dataset', "Unknown dataset [{$id}]."),
        };
        $byYear = [];
        foreach ($years as $year) {
            $byYear[$year->startYear] = $year;
        }
        krsort($byYear);

        return array_values($byYear);
    }

    public function query(string $id, string $aun, FiscalYear $year, array $filters = []): object
    {
        $context = app(PDEClientManager::class)->district($aun)->year($year);
        $query = match ($id) {
            'gfb-budgets' => $context->financials()->budget(),
            'afr-actuals' => $context->financials()->actual(),
            'fund-balance' => $context->financials()->fundBalance(),
            'indebtedness' => $context->financials()->indebtedness(),
            'real-estate-tax-rates' => $context->financials()->realEstateTaxRates(),
            'selected-data' => $context->financials()->selectedData(),
            'act-one-index' => $context->financials()->actOneIndex(),
            'public-enrollment' => $context->enrollments(),
            'enrollment-projections' => $context->enrollments()->onlyProjections(),
            'english-learners' => $context->enrollments()->onlyEnglishLearners(),
            'economically-disadvantaged' => $context->enrollments()->economicallyDisadvantaged(),
            'adm' => $context->enrollments()->adm(),
            'pssa' => $context->assessments()->pssa(),
            'keystone' => $context->assessments()->keystone(),
            'cohort-graduation' => $context->assessments()->graduation()->cohortYears($filters['cohort_years'] ?? 4),
            'dropouts' => $context->assessments()->graduation()->dropouts(),
            'professional-personnel' => $context->personnel(),
            default => throw new ToolException('unknown_dataset', "Unknown dataset [{$id}]."),
        };
        if (isset($filters['category'])) {
            match ($filters['category']) {
                'revenue' => $query->revenues(),
                'expenditure' => $query->expenditures(),
                'fund_balance' => $query->fundBalances(),
                default => throw new ToolException('invalid_arguments', 'Unknown financial category.'),
            };
        }
        foreach (['accounts' => 'account', 'grades' => 'grade', 'subjects' => 'subject', 'groups' => 'group', 'fund_types' => 'fundType', 'phases' => 'phase', 'personnel_categories' => 'category'] as $filter => $method) {
            if (isset($filters[$filter])) {
                $query->{$method}(...$filters[$filter]);
            }
        }

        return $query;
    }

    /** @return list<RowTable|YearTable> */
    public function tables(string $id, FiscalYear $year, array $filters = []): array
    {
        $financial = fn () => app(FinancialDataRepository::class);
        $enrollment = fn () => app(EnrollmentDataRepository::class);
        $elements = fn () => app(FinancialDataElementsRepository::class);
        if (in_array($id, ['gfb-budgets', 'afr-actuals'], true)) {
            $tables = [];
            foreach (isset($filters['category']) ? [$filters['category']] : ['revenue', 'expenditure'] as $category) {
                $method = match ([$id, $category]) {
                    ['gfb-budgets', 'revenue'], ['gfb-budgets', 'fund_balance'] => 'budgetRevenues',
                    ['gfb-budgets', 'expenditure'] => 'budgetExpenditures',
                    ['afr-actuals', 'revenue'] => 'actualRevenues',
                    ['afr-actuals', 'expenditure'] => 'actualExpenditures',
                    default => null,
                };
                if ($method !== null) {
                    try {
                        $tables[] = $financial()->{$method}($year);
                    } catch (DataSetNotFoundException $exception) {
                        if ($exception->matches > 1) {
                            throw $exception;
                        }
                    }
                }
            }

            return $tables;
        }

        return [match ($id) {
            'fund-balance' => $financial()->fundBalance($year),
            'indebtedness' => $financial()->indebtedness($year),
            'public-enrollment' => $enrollment()->publicTable($year),
            'enrollment-projections' => $enrollment()->projectionTable($year),
            'english-learners' => $enrollment()->elTable($year),
            'economically-disadvantaged' => $enrollment()->economicallyDisadvantagedTable($year),
            'adm' => $elements()->admTable($year),
            'real-estate-tax-rates' => $elements()->taxRateTable($year),
            'selected-data' => $elements()->selectedDataTable($year),
            'act-one-index' => app(ActOneIndexRepository::class)->table($year),
            'pssa', 'keystone' => app(AssessmentDataRepository::class)->table($id, $year),
            'cohort-graduation' => app(GraduationDataRepository::class)->cohortTable(CohortSpan::from($filters['cohort_years'] ?? 4), $year),
            'dropouts' => app(GraduationDataRepository::class)->dropoutTable($year),
            'professional-personnel' => app(PersonnelDataRepository::class)->table($year),
            default => throw new ToolException('unknown_dataset', "Unknown dataset [{$id}]."),
        }];
    }
}
