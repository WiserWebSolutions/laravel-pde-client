<?php

namespace WiserWebSolutions\PDEClient\Tests\Documentation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Enums\MissingDataBehavior;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Tests\Support\DocumentationFixtures;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class DocumentationExamplesTest extends TestCase
{
    public function test_documented_combined_budget_and_actual_example(): void
    {
        Http::preventStrayRequests();
        DocumentationFixtures::register($this->app);
        $query = PDE::district('124157203')->year('2024-2025')->financials()->budgetAndActual()->revenues()->account('6111');
        $results = $query->get();
        $this->assertInstanceOf(Collection::class, $results);
        $result = $results->sole();
        $account = $result->accounts->sole();
        $this->assertSame(1000.0, $account->budget);
        $this->assertSame(1100.0, $account->actual);
        $this->assertSame(100.0, $account->variance());
        $this->assertTrue($account->hasData('budget'));
        $this->assertTrue($account->hasData('actual'));
        $this->assertSame(1000.0, $query->total(Measure::Budget, FinancialCategory::Revenue));
        $this->assertSame(1100.0, $query->total(Measure::Actual, FinancialCategory::Revenue));
        $this->assertSame($result->toArray(), $query->first()->toArray());
        $this->assertSame($result->toArray(), $query->sole()->toArray());
        $this->assertInstanceOf(LazyCollection::class, $query->records());
        $this->assertSame($account->toArray(), $query->records()->sole()->toArray());
        $default = PDE::district('124157203')->year('2024-2025')->financials()->revenues()->account('6111')->sole();
        $this->assertSame($result->toArray(), $default->toArray());
        $expected = json_decode(file_get_contents(__DIR__.'/../Fixtures/documentation/financial-comparison.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, json_decode($result->toJson(), true, flags: JSON_THROW_ON_ERROR));
        $page = file_get_contents(__DIR__.'/../../docs/financial-comparisons.md');
        $this->assertStringContainsString("PDE::district('124157203')->year('2024-2025')->financials()->budgetAndActual()->revenues()->account('6111')", $page);
        $this->assertStringContainsString(json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $page);
    }

    public static function endpoints(): array
    {
        $definitions = json_decode(file_get_contents(__DIR__.'/../Fixtures/documentation/endpoints.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn ($id, $definition) => [$id, $definition], array_keys($definitions), array_values($definitions));
    }

    #[DataProvider('endpoints')]
    public function test_every_documented_endpoint_chain_and_json_example(string $id, array $definition): void
    {
        Http::preventStrayRequests();
        DocumentationFixtures::register($this->app);
        $query = eval('return '.str_replace('PDE::', '\\WiserWebSolutions\\PDEClient\\Facades\\PDE::', $definition['expression']).';');
        $results = $query->get();
        $this->assertInstanceOf(Collection::class, $results);
        $result = $results->sole();
        $value = eval('return '.$definition['access'].';');
        $this->assertEquals($definition['expected'], $value);
        $this->assertInstanceOf('WiserWebSolutions\\PDEClient\\'.match ($definition['type']) {
            'FinancialYearSummary', 'FundBalanceRecord', 'IndebtednessRecord' => 'FinancialData\\',
            'EnrollmentYearSummary', 'EconomicallyDisadvantagedRecord' => 'Enrollment\\',
            'AssessmentRecord' => 'Assessment\\',
            'GraduationRecord', 'DropoutRecord' => 'Graduation\\',
            'PersonnelRecord' => 'Personnel\\',
            default => 'FinancialDataElements\\',
        }.$definition['type'], $result);
        $this->assertSame($result->toArray(), $query->first()->toArray());
        $this->assertSame($result->toArray(), $query->sole()->toArray());
        $actual = json_decode($result->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $expected = json_decode(file_get_contents(__DIR__.'/../Fixtures/documentation/'.$id.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $actual);
        $page = file_get_contents(__DIR__.'/../../docs/datasets/'.$id.'.md');
        $this->assertStringContainsString($definition['expression'], $page);
        $this->assertStringContainsString(json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $page);
        $this->assertInstanceOf(LazyCollection::class, $query->records());
        $this->assertSame(1, $query->records()->count());
        $this->assertFixtureOmissionIsGuarded($query->records()->first(), $definition['field']);
    }

    /** Tests DTO policy only; actual historical schema evidence is tested separately. */
    private function assertFixtureOmissionIsGuarded(object $record, string $field): void
    {
        $constructor = (new \ReflectionClass($record))->getConstructor();
        $create = function (MissingDataBehavior $behavior) use ($record, $field, $constructor) {
            $arguments = [];
            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();
                $arguments[] = match ($name) {
                    'availability' => new AvailabilityMetadata(
                        [$field => 'not_collected'],
                        context: ['dataset' => 'policy-only fixture', 'source' => 'https://fixtures.example/omission.xlsx'],
                        behavior: $behavior,
                    ),
                    $field => null,
                    default => $record->{$name},
                };
            }

            return (new \ReflectionClass($record))->newInstanceArgs($arguments);
        };
        $strict = $create(MissingDataBehavior::ThrowException);
        $this->assertFalse($strict->hasData($field));
        foreach (['property', 'array'] as $operation) {
            try {
                $operation === 'property' ? $strict->{$field} : $strict->toArray();
                $this->fail('Included uncollected fixture fields must throw.');
            } catch (DataNotCollectedException $exception) {
                $this->assertSame($field, $exception->field);
            }
        }
        $this->assertSame(['aun' => '124157203'], $strict->only('aun')->toArray());
        $nullable = $create(MissingDataBehavior::ReturnNull);
        $this->assertNull($nullable->{$field});
        $serialized = $nullable->toArray();
        $key = Str::snake($field);
        $this->assertArrayHasKey($key, $serialized);
        $this->assertNull($serialized[$key]);
    }
}
