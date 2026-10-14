<?php

namespace WiserWebSolutions\PDEClient\Tests\AgentData;

use PHPUnit\Framework\Attributes\DataProvider;
use WiserWebSolutions\PDEClient\AgentData\DatasetAccess;
use WiserWebSolutions\PDEClient\AgentData\RecordSerializer;
use WiserWebSolutions\PDEClient\AgentData\SourceReference;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\FinancialData\FinancialRecord;
use WiserWebSolutions\PDEClient\FinancialData\IndebtednessRecord;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\MetricMap;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\Support\DocumentationFixtures;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class RecordSerializerTest extends TestCase
{
    public static function unavailableStates(): array
    {
        return array_map(fn ($state) => [$state->value], array_filter(DataAvailability::cases(), fn ($state) => $state !== DataAvailability::Available));
    }

    #[DataProvider('unavailableStates')]
    public function test_every_unavailable_reason_and_reported_zero_survive_strict_serialization(string $state): void
    {
        $record = new FinancialRecord('001234567', 'District', null, '2024-2025',
            FinancialCategory::Revenue, '6111', null, null, 0.0, null,
            new AvailabilityMetadata(['actual' => $state]));
        $result = app(RecordSerializer::class)->serialize($record);
        $this->assertSame(0.0, $result['values']['budget']);
        $this->assertNull($result['values']['actual']);
        $this->assertSame($state, $result['availability']['actual']);
        if ($state === 'not_collected') {
            $this->expectException(DataNotCollectedException::class);
            $record->toArray();
        }
    }

    public function test_nested_maps_remain_safe_and_literal_keys_are_retained(): void
    {
        DocumentationFixtures::register($this->app);
        $record = app(DatasetAccess::class)
            ->query('indebtedness', DocumentationFixtures::AUN, FiscalYear::parse(2024))->sole();
        $map = new MetricMap(['A.B' => 0, 'Private' => null, 'Historical' => null], new AvailabilityMetadata([
            'Private' => 'suppressed', 'Historical' => 'not_collected',
        ]));
        $replacement = new IndebtednessRecord($record->aun, $record->districtName, $record->county, $record->fiscalYear,
            $record->fundType, $record->phase, $record->total, $map);
        $result = app(RecordSerializer::class)->serialize($replacement);
        $this->assertSame(0.0, $result['values']['categories']['A.B']);
        $this->assertSame('suppressed', $result['availability']['categories.Private']);
        $this->assertSame('not_collected', $result['availability']['categories.Historical']);
        $this->expectException(DataNotCollectedException::class);
        $map->toArray();
    }

    public function test_download_manifest_provenance_survives_table_cache_round_trip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pde-agent-');
        try {
            file_put_contents($path, 'fixture workbook bytes');
            file_put_contents($path.'.pde-source.json', json_encode([
                'url' => 'https://fixtures.example/data.xlsx', 'sha256' => hash_file('sha256', $path), 'downloaded_at' => 1791979200,
            ]));
            $sources = SourceReference::forPaths($path);
            $table = DocumentationFixtures::year(['6111' => 0], 'gfb')->withSources($sources);
            $copy = YearTable::fromArray($table->toArray());
            $this->assertSame($sources, $copy->sources);
            $this->assertSame(hash_file('sha256', $path), $sources[0]['sha256']);
            $this->assertNotNull($sources[0]['downloaded_at']);
            $row = DocumentationFixtures::row([['rate' => 0]], 'graduation')->withSources($sources);
            $copy = RowTable::fromArray($row->toArray());
            $this->assertSame($sources, $copy->availabilityFor(DocumentationFixtures::AUN, 0)->context['sources']);
        } finally {
            unlink($path);
            unlink($path.'.pde-source.json');
        }
    }
}
