<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use WiserWebSolutions\PDEClient\Tests\Documentation\DocumentationExamplesTest;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class MigrationTemplatesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'pde_templates');
        $app['config']->set('database.connections.pde_templates', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    public function test_templates_store_all_documented_flat_records_and_roll_back(): void
    {
        $migrations = [];
        foreach (glob(__DIR__.'/../../migrations/*.php') as $path) {
            $migration = require $path;
            $migration->up();
            $migrations[] = $migration;
        }
        $this->assertCount(13, $migrations);
        $tables = [
            'FinancialRecord' => 'financial_records',
            'IndebtednessRecord' => 'indebtedness_records',
            'FundBalanceRecord' => 'fund_balance_records',
            'AssessmentRecord' => 'assessment_records',
            'GraduationRecord' => 'graduation_records',
            'DropoutRecord' => 'dropout_records',
            'EnrollmentRecord' => 'enrollment_records',
            'EconomicallyDisadvantagedRecord' => 'economically_disadvantaged_records',
            'PersonnelRecord' => 'personnel_records',
            'AdmRecord' => 'adm_records',
            'RealEstateTaxRateRecord' => 'real_estate_tax_rate_records',
            'SelectedDataRecord' => 'selected_data_records',
            'ActOneIndexRecord' => 'act_one_index_records',
        ];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'index'") as $index) {
            $this->assertLessThanOrEqual(64, strlen($index->name), 'Index names must also fit MySQL.');
        }
        DocumentationFixtures::register($this->app);

        foreach (DocumentationExamplesTest::endpoints() as [$id, $definition]) {
            $query = eval('return '.str_replace('PDE::', '\\WiserWebSolutions\\PDEClient\\Facades\\PDE::', $definition['expression']).';');
            $record = $query->records()->first();
            $table = $tables[class_basename($record)];
            $values = $record->toArray();
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $values[$key] = json_encode($value, JSON_THROW_ON_ERROR);
                }
                $this->assertTrue(Schema::hasColumn($table, $key), $id.': '.$key);
            }
            DB::table($table)->insert($values);
            $this->assertSame(1, DB::table($table)->count());
            DB::table($table)->delete();
        }

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
