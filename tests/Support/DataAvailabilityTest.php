<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use WiserWebSolutions\PDEClient\Enrollment\EconomicallyDisadvantagedRecord;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentRecord;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentSummaryBuilder;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentYearSummary;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\FinancialDataElements\AdmRecord;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\MetricMap;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class DataAvailabilityTest extends TestCase
{
    private function record(?AvailabilityMetadata $metadata = null): AdmRecord
    {
        return new AdmRecord('124157203', 'Fixture District', 'Fixture County', '2015-2016', 0.0, 155.0, 152.0, null, null, null, 1.02, ['Present' => 5.0], $metadata ?? new AvailabilityMetadata(
            ['nonresidentAdm' => DataAvailability::NotCollected, 'totalAdmPde363' => DataAvailability::Suppressed],
            context: ['dataset' => 'adm', 'source' => 'https://fixtures.example/adm.xlsx'],
        ));
    }

    public function test_inspection_is_safe_and_zero_is_available(): void
    {
        $record = $this->record();
        $this->assertSame(0.0, $record->adm);
        $this->assertTrue($record->hasData('adm'));
        $this->assertFalse($record->hasData('nonresidentAdm'));
        $this->assertSame(DataAvailability::NotCollected, $record->availability('nonresidentAdm'));
        $this->assertSame(DataAvailability::Suppressed, $record->availability('totalAdmPde363'));
        $this->assertNull($record->totalAdmPde363);
        $this->assertSame(DataAvailability::NotReported, $record->availability('specialEducationAdm'));
    }

    public static function guardedReads(): array
    {
        return [['property'], ['isset'], ['coalesce'], ['array'], ['json'], ['json_encode'], ['raw_names']];
    }

    #[DataProvider('guardedReads')]
    public function test_strict_reads_and_exports_throw_with_source_context(string $operation): void
    {
        $record = $this->record();
        try {
            match ($operation) {
                'property' => $record->nonresidentAdm,
                'isset' => isset($record->nonresidentAdm),
                'coalesce' => $record->nonresidentAdm ?? 0.0,
                'array' => $record->toArray(),
                'json' => $record->toJson(),
                'json_encode' => json_encode($record),
                'raw_names' => $record->transform(TransformationContextFactory::create()->withPropertyNameMapping(false)),
            };
            $this->fail('Missing historical data must not silently become null or zero.');
        } catch (DataNotCollectedException $exception) {
            $this->assertSame('nonresidentAdm', $exception->field);
            $this->assertSame('2015-2016', $exception->context['year']);
            $this->assertSame('124157203', $exception->context['district']);
            $this->assertSame('https://fixtures.example/adm.xlsx', $exception->context['source']);
        }
    }

    public function test_null_configuration_preserves_the_field_and_its_reason(): void
    {
        config(['pde-client.missing_data' => 'return_null']);
        $record = $this->record();
        $this->assertNull($record->nonresidentAdm);
        $this->assertFalse(isset($record->nonresidentAdm));
        $this->assertSame(0.0, $record->nonresidentAdm ?? 0.0);
        $this->assertArrayHasKey('nonresident_adm', $record->toArray());
        $this->assertNull(json_decode($record->toJson(), true)['nonresident_adm']);
        $this->assertSame(DataAvailability::NotCollected, $record->availability('nonresidentAdm'));
        config(['pde-client.missing_data' => 'throw']);
        $this->assertNull($record->nonresidentAdm, 'A DTO snapshots its policy rather than changing after construction.');
    }

    public function test_field_projections_do_not_read_excluded_historical_fields(): void
    {
        $this->assertSame(['aun' => '124157203', 'adm' => 0.0], $this->record()->only('aun', 'adm')->toArray());
        $array = $this->record()->except('nonresidentAdm')->toArray();
        $this->assertArrayNotHasKey('nonresident_adm', $array);
        $this->assertNull($array['total_adm_pde363']);
    }

    public function test_metric_maps_are_immutable_and_keep_literal_numeric_keys(): void
    {
        $map = new MetricMap([1 => 0.0, 'Kindergarten full day' => 20.0], new AvailabilityMetadata(['New metric' => 'not_collected']));
        $this->assertTrue($map->hasData(1));
        $this->assertSame(0.0, $map[1]);
        $this->assertSame([1 => 0.0], $map->only('1')->toArray());
        $this->assertSame([1 => 0.0, 'Kindergarten full day' => 20.0], $map->except('New metric')->toArray());
        $this->expectException(Error::class);
        $map[1] = 50;
    }

    public function test_metric_map_missing_keys_throw_and_nested_exclusions_work(): void
    {
        $metadata = new AvailabilityMetadata(maps: ['breakdown' => ['New metric' => 'not_collected']]);
        $record = $this->record($metadata);
        $this->assertSame(['Present' => 5.0], $record->only('breakdown.Present')->toArray()['breakdown']);
        $this->assertSame(['Present' => 5.0], $this->record($metadata)->except('breakdown.New metric')->toArray()['breakdown']);
        $this->expectException(DataNotCollectedException::class);
        $this->record($metadata)->breakdown['New metric'];
    }

    public function test_metric_map_null_mode_and_unknown_keys(): void
    {
        config(['pde-client.missing_data' => 'return_null']);
        $map = new MetricMap([], new AvailabilityMetadata(['Known metric' => 'not_collected']));
        $this->assertSame(['Known metric' => null], $map->toArray());
        $this->assertNull($map['Known metric']);
        $this->expectException(InvalidArgumentException::class);
        $map['Typo'];
    }

    public function test_availability_survives_normalized_table_cache_round_trips_and_hydration(): void
    {
        $table = new RowTable(['124157203' => ['name' => 'Fixture']], ['124157203' => [['nonresident_adm' => null, 'adm' => 0.0]]],
            ['fields' => ['nonresident_adm' => 'not_collected']], source: 'https://fixtures.example/old.xlsx');
        $table = RowTable::fromArray(json_decode(json_encode($table->toArray()), true));
        $metadata = $table->availabilityFor('124157203', 0);
        $this->assertSame(DataAvailability::NotCollected, $metadata->state('nonresidentAdm', null));
        $record = AdmRecord::from([
            'aun' => '124157203', 'district_name' => 'Fixture', 'county' => null, 'school_year' => '2015-2016',
            'adm' => 0.0, 'wadm' => null, 'adjusted_adm' => null, 'nonresident_adm' => null, 'total_adm_pde363' => null,
            'special_education_adm' => null, 'adjustment_factor' => null, 'breakdown' => [], 'availability' => $metadata,
        ]);
        $this->assertSame(DataAvailability::NotCollected, $record->availability('nonresidentAdm'));
        $this->expectException(DataNotCollectedException::class);
        $record->nonresidentAdm;
    }

    public function test_unknown_properties_and_invalid_configuration_are_programming_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->record()->availability('typo');
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        config(['pde-client.missing_data' => 'sometimes']);
        $this->expectException(InvalidArgumentException::class);
        $this->record();
    }

    public function test_native_object_cache_serialization_restores_lazy_guards(): void
    {
        $record = unserialize(serialize($this->record()));
        $this->assertSame(DataAvailability::NotCollected, $record->availability('nonresidentAdm'));
        $this->expectException(DataNotCollectedException::class);
        $record->nonresidentAdm;
    }

    public function test_uncollected_readonly_properties_cannot_be_assigned_externally(): void
    {
        $this->expectException(Error::class);
        $this->record()->nonresidentAdm = 1;
    }

    public function test_nested_collections_enforce_policy_after_selective_exclusions(): void
    {
        $grade = new EnrollmentRecord(
            '124157203', 'Fixture', 'Fixture County', 'SD', '2015-2016', '1',
            null, null, null, null, null, null, new AvailabilityMetadata(['count' => 'not_collected']),
        );
        $makeSummary = fn () => new EnrollmentYearSummary(
            '124157203', 'Fixture', 'Fixture County', 'SD', '2015-2016',
            null, null, null, null, null, collect([$grade]),
        );
        $this->assertSame(['grades' => [['grade' => '1']]], $makeSummary()->only('grades.grade')->toArray());
        $this->assertArrayNotHasKey('count', $makeSummary()->except('grades.count')->toArray()['grades'][0]);
        $this->expectException(DataNotCollectedException::class);
        $makeSummary()->toJson();
    }

    public function test_metric_map_isset_and_coalescing_enforce_strict_policy(): void
    {
        foreach (['isset', 'coalesce', 'get', 'json'] as $operation) {
            $map = new MetricMap([], new AvailabilityMetadata(['Known' => 'not_collected']));
            try {
                match ($operation) {
                    'isset' => isset($map['Known']),
                    'coalesce' => $map['Known'] ?? 0,
                    'get' => $map->get('Known'),
                    'json' => $map->toJson(),
                };
                $this->fail('Uncollected metric must throw for '.$operation);
            } catch (DataNotCollectedException $exception) {
                $this->assertSame('metrics[Known]', $exception->field);
            }
        }
    }

    public function test_uncollected_metrics_cannot_contain_reported_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MetricMap(['Known' => 0.0], new AvailabilityMetadata(['Known' => 'not_collected']));
    }

    public function test_derived_uncollected_totals_retain_the_source_context(): void
    {
        $grade = new EnrollmentRecord('124157203', 'Fixture', 'Fixture County', 'SD', '2015-2016', '1',
            100.0, ['001' => 100.0], null, null, null, null,
            new AvailabilityMetadata(context: ['source' => 'https://fixtures.example/enrollment.xlsx']),
        );
        $disadvantaged = new EconomicallyDisadvantagedRecord(
            '124157203', 'Fixture', 'SD', 'Fixture County', '2015-2016', null, 100.0, null,
            new AvailabilityMetadata(['economicallyDisadvantagedCount' => 'not_collected'], context: ['source' => 'https://fixtures.example/low-income.xlsx']),
        );
        $summary = app(EnrollmentSummaryBuilder::class)->build(collect([$grade]), $disadvantaged, true);
        $this->assertSame(DataAvailability::NotCollected, $summary->availability('economicallyDisadvantagedTotal'));
        try {
            $summary->economicallyDisadvantagedTotal;
            $this->fail('An uncollected derived total must be guarded.');
        } catch (DataNotCollectedException $exception) {
            $this->assertStringContainsString('https://fixtures.example/low-income.xlsx', $exception->context['source']);
        }
    }
}
