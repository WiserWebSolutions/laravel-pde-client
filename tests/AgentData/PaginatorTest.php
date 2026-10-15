<?php

namespace WiserWebSolutions\PDEClient\Tests\AgentData;

use WiserWebSolutions\PDEClient\AgentData\Paginator;
use WiserWebSolutions\PDEClient\AgentData\Result;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class PaginatorTest extends TestCase
{
    public function test_identical_bytes_can_be_redownloaded_without_invalidating_a_cursor(): void
    {
        $source = ['url' => 'https://fixtures.example/report.xlsx', 'sha256' => str_repeat('a', 64), 'downloaded_at' => '2026-10-14T12:00:00+00:00'];
        $rows = [
            ['values' => ['count' => 0], 'availability' => ['count' => 'available'], 'sources' => [$source]],
            ['values' => ['count' => 1], 'availability' => ['count' => 'available'], 'sources' => [$source]],
        ];
        $paginator = new Paginator;
        $first = $paginator->page(Result::make($rows, sources: [$source]), ['limit' => 1]);
        $source['downloaded_at'] = '2026-10-15T12:00:00+00:00';
        foreach ($rows as &$row) {
            $row['sources'] = [$source];
        }
        unset($row);
        $next = $paginator->page(Result::make($rows, sources: [$source]), ['limit' => 1, 'cursor' => $first['pagination']['next_cursor']]);
        $this->assertSame(1, $next['data'][0]['values']['count']);
        $this->assertFalse($next['pagination']['has_more']);
    }
}
