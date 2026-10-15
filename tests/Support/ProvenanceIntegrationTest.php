<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use WiserWebSolutions\PDEClient\Tests\Documentation\DocumentationExamplesTest;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/** Source identity must survive the real finder/download/parser/cache pipeline. */
class ProvenanceIntegrationTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public static function endpoints(): array
    {
        return DocumentationExamplesTest::endpoints();
    }

    #[DataProvider('endpoints')]
    public function test_every_dataset_preserves_discovered_sources_through_caching(string $id, array $definition): void
    {
        config(['pde-client.parsed_cache_ttl' => 3600, 'pde-client.missing_data' => 'return_null']);
        Http::preventStrayRequests();
        [$config, $path, $label, $sheets] = $this->report($id);
        $url = 'https://pde-client-tests.example'.$path;
        $workbook = $this->xlsxFixture($sheets);
        $responses = [$url => Http::response(file_get_contents($workbook))];
        $links = '<a href="'.$url.'">'.$label.'</a>';
        if ($id === 'afr-actuals') {
            foreach (['State Revenue' => '7110', 'Federal Revenue' => '8110', 'Other Revenue' => '9110'] as $kind => $code) {
                $other = 'https://pde-client-tests.example/details/'.$code.'.xlsx';
                $file = $this->xlsxFixture(['2024-25' => [
                    ['AUN', 'LEA Name', 'County', 'Fixture '.$code], [124157203, 'Fixture District', 'Fixture County', 100],
                ]]);
                $responses[$other] = Http::response(file_get_contents($file));
                $links .= '<a href="'.$other.'">'.$kind.': 2024-25</a>';
            }
        }
        $page = config('pde-client.'.$config.'.page_url');
        $responses[$page] = Http::response($links);
        Http::fake($responses);
        $makeQuery = fn () => eval('return '.str_replace('PDE::', '\\WiserWebSolutions\\PDEClient\\Facades\\PDE::', $definition['expression']).';');
        $record = $makeQuery()->records()->first();
        $this->assertNotNull($record, $id);
        $this->assertTrue($record->hasData($definition['field']), $id);
        $sources = $record->sourcesFor($definition['field']);
        $this->assertSame([$url], array_column($sources, 'url'), $id);
        $this->assertSame($page, $sources[0]->pageUrl);
        $this->assertSame($label, $sources[0]->label);
        $this->assertSame(hash_file('sha256', $workbook), $sources[0]->sha256);
        $this->assertNotNull($sources[0]->downloadedAt);
        $export = json_decode($record->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $copy = $record::from($export);
        $this->assertSame($export['provenance'], $copy->provenance->toArray());

        $requests = count(Http::recorded());
        $this->app->forgetScopedInstances();
        $cached = $makeQuery()->records()->first();
        $this->assertSame($record->provenance->toArray(), $cached->provenance->toArray());
        Http::assertSentCount($requests);
    }

    /** @return array{string, string, string, array} */
    private function report(string $id): array
    {
        $district = [124157203, 'Fixture District', 'Fixture County'];

        return match ($id) {
            'gfb-budgets' => ['financial_data.gfb', '/gfb/2024-25.xlsx', '2024-25 GFB Data', [
                'Rev_BegFB' => [['AUN', 'InstName', 'CountyName', 6111], [...$district, 1000]],
            ]],
            'afr-actuals' => ['financial_data.afr', '/details/localrev.xlsx', 'Local Revenue: 2024-25', [
                '2024-25' => [['AUN', 'LEA Name', 'County', 'Fixture 6111'], [...$district, 1100]],
            ]],
            'fund-balance' => ['financial_data.afr', '/details/genfundbalance.xlsx', 'General Fund Balance: 2024-25', [
                '2024-25' => [['AUN', 'LEA Name', 'County', 'Committed', 'Assigned', 'Unassigned'], [...$district, 100, 200, 300]],
            ]],
            'indebtedness' => ['financial_data.afr', '/details/soin.xlsx', 'Short- and Long-Term Debt: 2024-25', [
                '2024-25' => [
                    ['', '', '', 'Governmental Fund Types -- Debt Outstanding at End of Fiscal Year'],
                    ['AUN', 'LEA Name', 'County', 'Bonds'], [...$district, 1000],
                ],
            ]],
            'real-estate-tax-rates' => ['financial_data_elements', '/real-estate-tax-rates/2024-25.xlsx', '2024-25 Real Estate Tax Rates', [
                'Rates' => [['AUN', 'School District', 'County', 'Millage', 'Municipality / Other Info', 'Community College Millage'],
                    [...$district, 15.5, 'Fixture municipality', 0.2]],
            ]],
            'selected-data' => ['financial_data_elements', '/selected-data/2024-25.xlsx', '2024-25 Selected Data', [
                'Selected Data' => [['AUN', 'School District', 'County', '2024-25 Aid Ratio', 'AIE Per WADM', 'Total Exp Per ADM'], [...$district, 0.5, 9000, 12000]],
            ]],
            'act-one-index' => ['act_one_index', '/act1index/adjindexhistory.xlsx', 'Adjusted Index History', [
                'SD Adj Index History' => [['AUN', 'School District', 'County', '2024-25'], [...$district, 0.041]],
            ]],
            'public-enrollment' => ['enrollment', '/enrollment/public-school/2024-25.xlsx', '2024-25 Public School Enrollment', [
                'LEA' => [['AUN', 'LEA Name', 'County', '1'], [...$district, 100]],
            ]],
            'enrollment-projections' => ['enrollment', '/enrollment/enrollment-projections/projections.xlsx', 'School District Enrollment Projections', [
                'Enrollment Projection Data' => [['Datatype', 'AUN', 'School Year', 'LEA Name', 'County', '1'],
                    ['Projection', 124157203, '2024-2025', 'Fixture District', 'Fixture County', 110]],
            ]],
            'english-learners' => ['enrollment', '/english-learners/2024-25.xlsx', '2024-25 English Learners', [
                'By LEA, School and Grade_6' => [['AUN', 'LEA Name', 'School Number', 'School Name', '1', 'Total'],
                    [124157203, 'Fixture District', '1001', 'Fixture School', 20, 20]],
            ]],
            'economically-disadvantaged' => ['enrollment', '/loan-cancellation/low-income.xlsx', 'Low Income Enrollments Public School', [
                'Low Income' => [['', '', '', '2024-2025', '', ''],
                    ['AUN', 'LEA', 'TYPE', 'Low Income', 'Enrollment', 'Percent'], [124157203, 'Fixture District', 'SD', 40, 100, 40]],
            ]],
            'adm' => ['financial_data_elements', '/average-daily-membership/2024-25.xlsx', '2024-25 ADM', [
                '2024-25 ADM-WADM' => [['AUN', 'School District', 'County', 'Average Daily Membership', 'Nonresident ADM'], [...$district, 100, 2]],
            ]],
            'pssa', 'keystone' => ['assessment', ($id === 'pssa' ? '/pssa-and-ayp-results/' : '/keystones/').'2025-'.$id.'-district-level-data.xlsx', '2025 '.$id.' District Level Data', [
                'District Data' => [
                    ['AUN', 'County', 'District Name', 'Subject', 'Group', 'Grade', 'Number Scored', 'Percent Advanced', 'Percent Proficient', 'Percent Basic', 'Percent Below Basic', 'Percent Proficient and above'],
                    [124157203, 'Fixture County', 'Fixture District', $id === 'pssa' ? 'Math' : 'Algebra I', 'All Students', $id === 'pssa' ? '3' : '11', 100, 20, 50, 20, 10, 70],
                ],
            ]],
            'cohort-graduation' => ['graduation', '/cohort-graduation-rates/2024-2025-4-year.xlsx', '2024-2025 4-year Cohort Graduation Rates', [
                '4 Year Grad Rate by LEA' => [['AUN', 'LEA', 'LEA Type', 'Grads', 'Cohort', 'Cohort Grad Rate'], [124157203, 'Fixture District', 'SD', 90, 100, 0.9]],
            ]],
            'dropouts' => ['graduation', '/dropouts/2024-25.xlsx', '2024-25 Dropouts', [
                'Summary by LEA' => [['AUN', 'LEA Name', 'County', 'Enrollment Grades 7-12', 'Male Dropouts', 'Female Dropouts', 'Dropouts', 'Dropout Rate'],
                    [...$district, 100, 2, 1, 3, 0.03]],
            ]],
            'professional-personnel' => ['personnel', '/prof-staff-summary/2024-25.xlsx', '2024-25 Staff Summary', [
                'LEA_Averages' => [['AUN', 'LEA Name', 'County', 'LEA Type', 'PP', 'AD', 'CT', 'Sal-CT', 'CO', 'OT'],
                    [...$district, 'SD', 15, 2, 10, 65000, 1, 2]],
            ]],
        };
    }
}
