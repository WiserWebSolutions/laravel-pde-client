<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Illuminate\Foundation\Application;
use WiserWebSolutions\PDEClient\Assessment\AssessmentDataRepository;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\Enums\CohortSpan;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FinancialDataElements\ActOneIndexRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\GraduationDataRepository;
use WiserWebSolutions\PDEClient\Personnel\PersonnelDataRepository;
use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Support\SourceReference;

/** Synthetic values shared by the executable endpoint examples. */
final class DocumentationFixtures
{
    public const AUN = '124157203';

    public const DISTRICT = ['name' => 'Fixture District', 'county' => 'Fixture County', 'lea_type' => 'SD'];

    public static function years(): array
    {
        return [FiscalYear::parse(2024)];
    }

    public static function row(array $rows, string $dataset): RowTable
    {
        return new RowTable([self::AUN => self::DISTRICT], [self::AUN => $rows], source: 'https://fixtures.example/'.$dataset.'.xlsx', provenance: self::provenance($dataset));
    }

    public static function year(array $amounts, string $dataset): YearTable
    {
        return new YearTable([self::AUN => self::DISTRICT], [self::AUN => $amounts], ['6111' => 'Current real estate taxes'], source: 'https://fixtures.example/'.$dataset.'.xlsx', provenance: self::provenance($dataset));
    }

    public static function provenance(string $dataset): DataProvenance
    {
        return new DataProvenance([new SourceReference('Synthetic '.$dataset.' workbook',
            'https://fixtures.example/'.$dataset.'.xlsx', 'https://fixtures.example/reports/'.$dataset, '2024-2025')]);
    }

    public static function register(Application $app): void
    {
        $app->instance(FinancialDataRepository::class, new class extends FinancialDataRepository
        {
            public function __construct() {}

            public function availableBudgetYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function availableActualYears(?FinancialCategory $category = null): array
            {
                return DocumentationFixtures::years();
            }

            public function availableFundBalanceYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function availableIndebtednessYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function budgetRevenues(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['6111' => 1000.0], 'gfb');
            }

            public function budgetExpenditures(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['1110' => 2000.0], 'gfb');
            }

            public function actualRevenues(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['6111' => 1100.0], 'afr');
            }

            public function actualExpenditures(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['1110' => 2100.0], 'afr');
            }

            public function fundBalance(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['committed' => 1000.0, 'assigned' => 2000.0, 'unassigned' => 3000.0]], 'fund-balance');
            }

            public function indebtedness(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['fund_type' => 'governmental', 'phase' => 'end', 'total' => 1000.0, 'categories' => ['Bonds' => 1000.0]]], 'indebtedness');
            }
        });
        $app->instance(EnrollmentDataRepository::class, new class extends EnrollmentDataRepository
        {
            public function __construct() {}

            public function availablePublicYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function availableElYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function availableProjectionYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function availableEconomicallyDisadvantagedYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function publicTable(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['1' => 100.0], 'public-enrollment');
            }

            public function elTable(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['1' => 20.0], 'english-learners');
            }

            public function projectionTable(FiscalYear $year): YearTable
            {
                return DocumentationFixtures::year(['1' => 110.0], 'projections');
            }

            public function economicallyDisadvantagedTable(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['economically_disadvantaged' => 40.0, 'enrollment' => 100.0, 'percent' => 40.0]], 'economically-disadvantaged');
            }
        });
        $app->instance(FinancialDataElementsRepository::class, new class extends FinancialDataElementsRepository
        {
            public function __construct() {}

            public function availableAdmYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function availableTaxRateYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function availableSelectedDataYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function admTable(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['adm' => 100.0, 'wadm' => 105.0, 'adjusted_adm' => 102.0, 'nonresident_adm' => 2.0, 'total_adm_pde363' => 98.0, 'special_education_adm' => 10.0, 'adjustment_factor' => 1.02, 'breakdown' => ['ADM Kindergarten' => 20.0]]], 'adm');
            }

            public function taxRateTable(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['county' => 'Fixture County', 'notes' => 'Municipality A', 'mills' => 15.5, 'community_college_mills' => 0.2]], 'tax-rates');
            }

            public function selectedDataTable(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['aid_ratio' => 0.5, 'aid_ratio_rank' => 100.0, 'wadm' => 105.0, 'adm' => 100.0, 'adm_rank' => 200.0, 'equalized_mills' => 15.0, 'equalized_mills_rank' => 150.0, 'population_per_square_mile' => 500.0, 'population_per_square_mile_rank' => 50.0, 'instruction_expense_per_wadm' => 9000.0, 'instruction_expense_per_wadm_rank' => 300.0, 'total_expenditure_per_adm' => 12000.0, 'total_expenditure_per_adm_rank' => 250.0]], 'selected-data');
            }
        });
        $app->instance(ActOneIndexRepository::class, new class extends ActOneIndexRepository
        {
            public function __construct() {}

            public function availableYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function table(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['index' => 0.041]], 'act-one-index');
            }
        });
        $app->instance(AssessmentDataRepository::class, new class extends AssessmentDataRepository
        {
            public function __construct() {}

            public function availableYears(string $exam): array
            {
                return DocumentationFixtures::years();
            }

            public function table(string $exam, FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['subject' => $exam === 'pssa' ? 'Math' : 'Algebra I', 'group' => 'All Students', 'grade' => $exam === 'pssa' ? '3' : '11', 'scored' => 100.0, 'advanced' => 20.0, 'proficient' => 50.0, 'basic' => 20.0, 'below_basic' => 10.0, 'proficient_or_above' => 70.0]], $exam);
            }
        });
        $app->instance(GraduationDataRepository::class, new class extends GraduationDataRepository
        {
            public function __construct() {}

            public function availableCohortYears(CohortSpan $span): array
            {
                return DocumentationFixtures::years();
            }

            public function availableDropoutYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function cohortTable(CohortSpan $span, FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['group' => 'Total', 'graduates' => 90.0, 'cohort_size' => 100.0, 'rate' => 0.9]], 'cohort-graduation');
            }

            public function dropoutTable(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['enrollment' => 100.0, 'male_dropouts' => 2.0, 'female_dropouts' => 1.0, 'dropouts' => 3.0, 'rate' => 0.03]], 'dropouts');
            }
        });
        $app->instance(PersonnelDataRepository::class, new class extends PersonnelDataRepository
        {
            public function __construct() {}

            public function availableYears(): array
            {
                return DocumentationFixtures::years();
            }

            public function table(FiscalYear $year): RowTable
            {
                return DocumentationFixtures::row([['category' => 'classroom_teacher', 'count' => 10.0, 'female' => 6.0, 'male' => 4.0, 'salary' => 65000.0, 'service' => 12.0, 'lea_years' => 8.0, 'education' => 3.0]], 'professional-personnel');
            }
        });
    }
}
