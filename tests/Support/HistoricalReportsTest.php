<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use WiserWebSolutions\PDEClient\Assessment\AssessmentDataRepository;
use WiserWebSolutions\PDEClient\Assessment\Parsing\AssessmentWorkbookParser;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\Enrollment\Parsing\PublicEnrollmentParser;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\DownloadFailedException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\Exceptions\StorageFailedException;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialData\FinancialDataRepository;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\FundBalanceParser;
use WiserWebSolutions\PDEClient\FinancialDataElements\FinancialDataElementsRepository;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\AdmParser;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Graduation\GraduationDataRepository;
use WiserWebSolutions\PDEClient\Graduation\Parsing\CohortRatesParser;
use WiserWebSolutions\PDEClient\Personnel\Parsing\StaffSummaryParser;
use WiserWebSolutions\PDEClient\Personnel\PersonnelDataRepository;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class HistoricalReportsTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_old_adm_columns_are_guarded_after_parsing_and_cache_round_trip(): void
    {
        $path = $this->xlsxFixture(['ADM-WADM' => [
            ['AUN', 'School District', 'County', 'Average Daily Membership', 'Weighted Average Daily Membership', 'Adjusted ADM', 'Adjustment Factor'],
            [124157203, 'Fixture District', 'Fixture County', 0, 155, 152, 1.02],
        ]]);
        $table = RowTable::fromArray(app(AdmParser::class)->parse($path)->toArray());
        $repository = $this->createStub(FinancialDataElementsRepository::class);
        $repository->method('admTable')->willReturn($table);
        $this->app->instance(FinancialDataElementsRepository::class, $repository);
        $query = PDE::district('124157203')->year(2015)->enrollments()->adm();
        $record = $query->sole();
        $this->assertSame(0.0, $record->adm);
        $this->assertSame(DataAvailability::NotCollected, $record->availability('nonresidentAdm'));
        $this->assertSame(['adm' => 0.0], $record->only('adm')->toArray());
        try {
            $record->nonresidentAdm;
            $this->fail('Uncollected fields must throw.');
        } catch (DataNotCollectedException $exception) {
            $this->assertSame($path, $exception->context['source']);
        }
        config(['pde-client.missing_data' => 'return_null']);
        $this->assertNull($query->sole()->nonresidentAdm);
        $this->assertNull($query->sole()->toArray()['special_education_adm']);
    }

    public function test_staff_headcounts_remain_usable_when_averages_were_not_collected(): void
    {
        $path = $this->xlsxFixture(['LEA_Averages' => [
            ['AUN', 'LEA Name', 'LEA Type', 'County', 'PP', 'AD', 'CT', 'CO', 'OT'],
            [124157203, 'Fixture District', 'SD', 'Fixture County', 10, 1, 8, 0, 1],
        ]]);
        $repository = $this->createStub(PersonnelDataRepository::class);
        $repository->method('table')->willReturn(app(StaffSummaryParser::class)->parse($path));
        $this->app->instance(PersonnelDataRepository::class, $repository);
        $record = PDE::district('124157203')->year(2015)->personnel()->classroomTeachers()->sole();
        $this->assertSame(8.0, $record->count);
        $this->assertSame(DataAvailability::NotCollected, $record->availability('averageSalary'));
        $this->expectException(DataNotCollectedException::class);
        $record->averageSalary;
    }

    public function test_verified_absent_demographics_throw_or_produce_normal_empty_results(): void
    {
        $path = $this->xlsxFixture(['Grad Rate by LEA' => [
            ['AUN', 'LEA', 'LEA Type', 'Grads', 'Cohort', 'Cohort Grad Rate'],
            [124157203, 'Fixture District', 'SD', 90, 100, 0.9],
        ]]);
        $repository = $this->createStub(GraduationDataRepository::class);
        $repository->method('cohortTable')->willReturn(app(CohortRatesParser::class)->parse($path));
        $this->app->instance(GraduationDataRepository::class, $repository);
        $query = PDE::district('124157203')->year(2015)->assessments()->graduation()->group('Female');
        try {
            $query->get();
            $this->fail('Absent demographic columns must throw.');
        } catch (DataNotCollectedException $exception) {
            $this->assertSame('female', $exception->context['group']);
        }
        $this->assertCount(1, PDE::district('124157203')->year(2015)->assessments()->graduation()->get());
        config(['pde-client.missing_data' => 'return_null']);
        $this->assertTrue($query->get()->isEmpty());
        $this->assertNull($query->first());
        $this->expectException(DataSetNotFoundException::class);
        $query->sole();
    }

    public function test_absent_assessment_group_rows_are_not_evidence_of_noncollection(): void
    {
        $path = $this->xlsxFixture(['PSSA District Data' => [
            ['AUN', 'County', 'District Name', 'Subject', 'Group', 'Grade', 'Number Scored', 'Percent Advanced', 'Percent Proficient', 'Percent Basic', 'Percent Below Basic', 'Percent Proficient and above'],
            [124157203, 'Fixture County', 'Fixture District', 'Math', 'All Students', '3', 0, 0, 0, 0, 0, 0],
            [124157203, 'Fixture County', 'Fixture District', 'Math', 'Male', '3', '<11', '*', '*', '*', '*', '*'],
        ]]);
        $repository = $this->createStub(AssessmentDataRepository::class);
        $repository->method('table')->willReturn(app(AssessmentWorkbookParser::class)->parse($path));
        $this->app->instance(AssessmentDataRepository::class, $repository);
        $query = PDE::district('124157203')->year(2024)->assessments()->pssa()->group('Female');
        $this->assertTrue($query->get()->isEmpty());
        $male = PDE::district('124157203')->year(2024)->assessments()->pssa()->group('Male')->sole();
        $this->assertNull($male->scored);
        $this->assertSame(DataAvailability::Suppressed, $male->availability('scored'));
        $all = PDE::district('124157203')->year(2024)->assessments()->pssa()->allStudents()->sole();
        $this->assertSame(0.0, $all->scored);
        $this->assertTrue($all->hasData('scored'));
    }

    public function test_null_mode_does_not_hide_operational_failures(): void
    {
        config(['pde-client.missing_data' => 'return_null']);
        $repository = $this->createStub(AssessmentDataRepository::class);
        $repository->method('table')->willThrowException(new DownloadFailedException('HTTP 503'));
        $this->app->instance(AssessmentDataRepository::class, $repository);
        $this->expectException(DownloadFailedException::class);
        PDE::district('124157203')->year(2024)->assessments()->pssa()->get();
    }

    public function test_absent_group_queries_preserve_collection_and_sole_rules_across_years(): void
    {
        $old = $this->xlsxFixture(['Grad Rate by LEA' => [
            ['AUN', 'LEA', 'LEA Type', 'Grads', 'Cohort', 'Cohort Grad Rate'],
            [124157203, 'Fixture District', 'SD', 90, 100, 0.9],
        ]]);
        $new = $this->xlsxFixture(['Grad Rate by LEA' => [
            ['AUN', 'LEA', 'LEA Type', 'Grads', 'Cohort', 'Cohort Grad Rate', 'Female Grad Rate'],
            [124157203, 'Fixture District', 'SD', 90, 100, 0.9, 0.95],
        ]]);
        $parser = app(CohortRatesParser::class);
        $tables = [2015 => $parser->parse($old), 2024 => $parser->parse($new)];
        $repository = $this->createStub(GraduationDataRepository::class);
        $repository->method('cohortTable')->willReturnCallback(fn ($span, $year) => $tables[$year->startYear]);
        $repository->method('availableCohortYears')->willReturn([FiscalYear::parse(2015), FiscalYear::parse(2024)]);
        $this->app->instance(GraduationDataRepository::class, $repository);
        $query = PDE::district('124157203')->allYears()->assessments()->graduation()->group('Female');
        try {
            $query->get();
            $this->fail('One uncollected requested year must fail in strict mode.');
        } catch (DataNotCollectedException $exception) {
            $this->assertSame('2015-2016', $exception->context['year']);
        }
        config(['pde-client.missing_data' => 'return_null']);
        $this->assertCount(1, $query->get());
        $this->assertSame('2024-2025', $query->sole()->schoolYear);
        $this->assertSame(0.95, $query->first()->rate);
        $unfiltered = PDE::district('124157203')->allYears()->assessments()->graduation();
        $this->assertCount(3, $unfiltered->get());
        $this->expectException(DataSetNotFoundException::class);
        $unfiltered->sole();
    }

    public function test_null_mode_does_not_hide_financial_storage_failures(): void
    {
        config(['pde-client.missing_data' => 'return_null']);
        $repository = $this->createStub(FinancialDataRepository::class);
        $repository->method('budgetRevenues')->willThrowException(new StorageFailedException('Disk write failed'));
        $this->app->instance(FinancialDataRepository::class, $repository);
        $this->expectException(StorageFailedException::class);
        PDE::district('124157203')->year(2024)->financials()->budget()->revenues()->get();
    }

    public function test_null_mode_does_not_hide_enrollment_parsing_failures(): void
    {
        config(['pde-client.missing_data' => 'return_null']);
        $repository = $this->createStub(EnrollmentDataRepository::class);
        $repository->method('publicTable')->willThrowException(new PDEClientException('Missing required header'));
        $this->app->instance(EnrollmentDataRepository::class, $repository);
        $this->expectException(PDEClientException::class);
        PDE::district('124157203')->year(2024)->enrollments()->get();
    }

    public function test_required_structure_remains_a_parse_error_in_null_mode(): void
    {
        config(['pde-client.missing_data' => 'return_null']);
        $path = $this->xlsxFixture(['2024-25' => [
            ['AUN', 'LEA Name'],
            [124157203, 'Fixture District'],
        ]]);
        $this->expectException(PDEClientException::class);
        app(FundBalanceParser::class)->parseYear($path, FiscalYear::parse(2024));
    }

    public function test_missing_grade_components_do_not_become_partial_enrollment_totals(): void
    {
        $path = $this->xlsxFixture(['Enrollment' => [
            ['AUN', 'LEA Name', 'County', 'LEA Type', '001', '002'],
            [124157203, 'Fixture District', 'Fixture County', 'SD', 100, '*'],
        ]]);
        $table = app(PublicEnrollmentParser::class)->parse($path);
        $repository = $this->createStub(EnrollmentDataRepository::class);
        $repository->method('publicTable')->willReturn($table);
        $this->app->instance(EnrollmentDataRepository::class, $repository);
        $summary = PDE::district('124157203')->year(2024)->enrollments()->sole();
        $this->assertNull($summary->enrollmentTotal);
        $this->assertSame(DataAvailability::Suppressed, $summary->availability('enrollmentTotal'));
        $this->assertSame(100.0, $summary->grades->firstWhere('grade', '1')->count);
    }
}
