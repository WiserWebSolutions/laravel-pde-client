<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use WiserWebSolutions\PDEClient\Enums\EnrollmentMetric;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FinancialDataElements\AdmRecord;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Support\SourceReference;
use WiserWebSolutions\PDEClient\Tests\Documentation\DocumentationExamplesTest;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class ProvenanceTest extends TestCase
{
    public static function endpoints(): array
    {
        return DocumentationExamplesTest::endpoints();
    }

    #[DataProvider('endpoints')]
    public function test_every_dataset_exports_field_citations_and_rehydrates_them(string $id, array $definition): void
    {
        DocumentationFixtures::register($this->app);
        $query = eval('return '.str_replace('PDE::', '\\WiserWebSolutions\\PDEClient\\Facades\\PDE::', $definition['expression']).';');
        $record = $query->records()->first();
        $sources = $record->sourcesFor($definition['field']);
        $this->assertNotEmpty($sources, $id);
        foreach ((new \ReflectionClass($record))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $field = $property->getName();
            if ($field !== 'provenance' && ! $property->isStatic() && $record->hasData($field)) {
                $this->assertNotEmpty($record->sourcesFor($field), $id.': '.$field);
            }
        }
        $this->assertSame($sources, $record->sourcesFor(Str::snake($definition['field'])));
        foreach ($sources as $source) {
            $this->assertTrue(SourceReference::isPublicUrl($source->url));
            $this->assertTrue(SourceReference::isPublicUrl($source->pageUrl));
        }
        $array = json_decode($record->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $rehydrated = $record::from($array);
        $this->assertSame($array['provenance'], $rehydrated->provenance->toArray());
        $this->assertSame($array, json_decode($rehydrated->toJson(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($array['provenance'], unserialize(serialize($record))->provenance->toArray());
        $this->assertSame(['aun' => DocumentationFixtures::AUN], $record->only('aun')->toArray());
        $this->assertNotEmpty($query->sole()->provenance->sources);
    }

    public function test_budget_actual_variance_totals_and_summary_keep_distinct_sources(): void
    {
        DocumentationFixtures::register($this->app);
        $query = PDE::district(DocumentationFixtures::AUN)->year(2024)->financials()->revenues()->account('6111');
        $summary = $query->sole();
        $record = $summary->accounts->sole();
        $this->assertSame(['https://fixtures.example/gfb.xlsx'], array_column($record->sourcesFor('budget'), 'url'));
        $this->assertSame(['https://fixtures.example/afr.xlsx'], array_column($record->sourcesFor('actual'), 'url'));
        $this->assertSame(1000.0, $record->amountWithSources(Measure::Budget)->value);
        $variance = $record->varianceWithSources();
        $this->assertSame($record->variance(), $variance->value);
        $this->assertSame(['https://fixtures.example/gfb.xlsx', 'https://fixtures.example/afr.xlsx'], array_column($variance->provenance->sourcesFor('value'), 'url'));
        $total = $query->totalWithSources(Measure::Actual, FinancialCategory::Revenue);
        $this->assertSame($query->total(Measure::Actual, FinancialCategory::Revenue), $total->value);
        $this->assertSame(['https://fixtures.example/afr.xlsx'], array_column($total->provenance->sourcesFor('value'), 'url'));
        $this->assertNotEmpty($summary->sourcesFor('accounts'));
        $this->assertSame(array_column($record->sourcesFor('districtName'), 'id'), array_column($summary->sourcesFor('districtName'), 'id'));
        $balance = PDE::district(DocumentationFixtures::AUN)->year(2024)->financials()->fundBalance()->sole();
        $this->assertSame($balance->total(), $balance->totalWithSources()->value);
        $this->assertSame(['https://fixtures.example/fund-balance.xlsx'], array_column($balance->totalWithSources()->provenance->sourcesFor('value'), 'url'));
    }

    public function test_enrollment_fields_and_totals_cite_only_their_datasets(): void
    {
        DocumentationFixtures::register($this->app);
        $query = PDE::district(DocumentationFixtures::AUN)->year(2024)->enrollments()->withAllDatasets()->withProjections();
        $summary = $query->sole();
        $record = $summary->grades->sole();
        foreach (['count' => 'public-enrollment', 'projectedCount' => 'projections', 'englishLearnersCount' => 'english-learners'] as $field => $dataset) {
            $this->assertSame(['https://fixtures.example/'.$dataset.'.xlsx'], array_column($record->sourcesFor($field), 'url'));
        }
        $this->assertSame($record->sourcesFor('count'), $record->subCounts->sourcesFor('1'));
        $this->assertSame(array_column($record->sourcesFor('count'), 'id'), array_column($summary->sourcesFor('enrollmentTotal'), 'id'));
        $this->assertSame(array_column($record->sourcesFor('projectedCount'), 'id'), array_column($summary->sourcesFor('projectedEnrollmentTotal'), 'id'));
        $copy = $record::from($record->toArray());
        $this->assertSame($record->subCounts->toArray(), $copy->subCounts->toArray());
        $this->assertSame(array_column($copy->sourcesFor('subCounts'), 'id'), array_column($copy->subCounts->sourcesFor('1'), 'id'));
        $total = $query->totalWithSources(EnrollmentMetric::EnglishLearners);
        $this->assertSame($query->total(EnrollmentMetric::EnglishLearners), $total->value);
        $this->assertSame(['https://fixtures.example/english-learners.xlsx'], array_column($total->provenance->sourcesFor('value'), 'url'));
    }

    public function test_sources_can_be_inspected_without_reading_uncollected_fields(): void
    {
        DocumentationFixtures::register($this->app);
        $array = PDE::district(DocumentationFixtures::AUN)->year(2024)->enrollments()->adm()->sole()->toArray();
        $array['nonresident_adm'] = null;
        $array['availability'] = new AvailabilityMetadata(['nonresidentAdm' => 'not_collected']);
        $record = AdmRecord::from($array);
        $this->assertNotEmpty($record->sourcesFor('nonresident_adm'));
        $this->assertNotEmpty($record->only('provenance')->toArray()['provenance']['sources']);
        $this->expectException(DataNotCollectedException::class);
        $record->nonresidentAdm;
    }

    public function test_table_caches_preserve_registries_and_per_account_references(): void
    {
        $source = DocumentationFixtures::provenance('local-revenue')->sources[0];
        $table = new YearTable([], [], [], provenance: new DataProvenance([$source]), amountSources: ['123456789' => ['6111' => [$source->id]]]);
        $copy = YearTable::fromArray(json_decode(json_encode($table->toArray()), true));
        $this->assertSame($source->toArray(), $copy->sourcesForAmount('123456789', '6111')[0]->toArray());
        $row = DocumentationFixtures::row([['adm' => 1.0]], 'adm');
        $copy = RowTable::fromArray(json_decode(json_encode($row->toArray()), true));
        $this->assertSame($row->toArray()['provenance'], $copy->toArray()['provenance']);
        $this->assertNotEmpty($copy->availabilityFor(DocumentationFixtures::AUN, 0)->provenance->sources);
        $source = DocumentationFixtures::provenance('assessment')->sources[0];
        $row = new RowTable([], [], provenance: DataProvenance::forFields([$source], ['scored']));
        $this->assertSame([$source], $row->availabilityFor(DocumentationFixtures::AUN, 0,
            ['scored' => 'numberScored'])->provenance->sourcesFor('numberScored'));
    }

    public function test_local_paths_are_not_public_sources_and_unknown_fields_fail(): void
    {
        $this->assertNull(SourceReference::forPath(__FILE__));
        DocumentationFixtures::register($this->app);
        $record = PDE::district(DocumentationFixtures::AUN)->year(2024)->enrollments()->adm()->sole();
        $this->expectException(InvalidArgumentException::class);
        $record->sourcesFor('missing_field');
    }

    public function test_chart_citations_require_the_verified_bundled_resources(): void
    {
        $tree = (new ChartOfAccounts(__DIR__.'/../../resources/chart-of-accounts'))->treeFor(FinancialCategory::Revenue);
        $this->assertSame('2024-2025', $tree->sourcesFor('6111')[0]->period);
        $this->assertStringEndsWith('.pdf', $tree->sourcesFor('6111')[0]->url);
        $this->assertSame([], $tree->sourcesFor('not-a-code'));
    }

    public function test_metadata_projections_do_not_change_cached_citations(): void
    {
        $provenance = DocumentationFixtures::provenance('adm');
        $source = $provenance->sources[0];
        $expected = $provenance->toCacheArray();
        $this->assertSame(['url' => $source->url], $source->only('url')->toArray());
        $this->assertSame($expected, $provenance->only('sources.url')->toCacheArray());
        $this->assertSame(['sources' => [['url' => $source->url]]], $provenance->toArray());
    }

    public function test_projection_context_is_not_shared_between_results(): void
    {
        DocumentationFixtures::register($this->app);
        $values = PDE::district(DocumentationFixtures::AUN)->year(2024)->enrollments()->adm()->sole()->toArray();
        $values['provenance'] = DocumentationFixtures::provenance('adm');
        $first = AdmRecord::from($values);
        $second = AdmRecord::from($values);
        $first->sourcesFor('adm')[0]->only('url')->toArray();
        $this->assertSame($values['provenance']->toCacheArray()['sources'], $second->provenance->toArray()['sources']);

        $account = PDE::district(DocumentationFixtures::AUN)->year(2024)->financials()->budget()->revenues()->account('6111')->records()->sole();
        $expected = $account->provenance->toArray();
        $account->amountWithSources(Measure::Budget)->provenance->sources[0]->only('url')->toArray();
        $this->assertSame($expected, $account->provenance->toArray());
    }
}
