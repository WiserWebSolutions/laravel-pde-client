<?php

namespace WiserWebSolutions\PDEClient\Tests\FinancialData;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Facades\PDE;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class AfrIntegrationTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_actual_revenues_merge_only_the_four_revenue_workbooks(): void
    {
        Http::preventStrayRequests();
        $labels = ['localrev' => 'Local Revenue', 'staterev' => 'State Revenue', 'federalrev' => 'Federal Revenue', 'otherrev' => 'Other Revenue'];
        $codes = ['localrev' => '6111', 'staterev' => '7110', 'federalrev' => '8110', 'otherrev' => '9110'];
        $links = [];
        $responses = [];
        foreach ($labels as $kind => $label) {
            $url = 'https://pde-client-tests.example/details/'.$kind.'.xlsx';
            $links[] = '<a href="'.$url.'">'.$label.': 2024-25</a>';
            $path = $this->xlsxFixture(['2024-25' => [
                ['AUN', 'LEA Name', 'County', 'Fixture '.$codes[$kind]],
                [124157203, 'Fixture District', 'Fixture County', 100],
            ]]);
            $responses[$url] = Http::response(file_get_contents($path));
        }
        $responses['pde-client-tests.example/afr'] = Http::response(implode('', $links));
        Http::fake($responses);
        $query = PDE::district('124157203')->year(2024)->financials()->actual()->revenues();
        $this->assertSame(400.0, $query->total(Measure::Actual, FinancialCategory::Revenue));
        $this->assertSame(100.0, $query->account('6111')->records()->sole()->actual);
        $record = $query->records()->sole();
        $this->assertSame(['https://pde-client-tests.example/details/localrev.xlsx'], array_column($record->sourcesFor('actual'), 'url'));
        $this->assertSame('https://pde-client-tests.example/afr', $record->sourcesFor('actual')[0]->pageUrl);
        $this->assertSame([], $record->sourcesFor('budget'));
        $this->assertSame(64, strlen($record->sourcesFor('actual')[0]->sha256));
        Http::assertSentCount(5);
    }
}
