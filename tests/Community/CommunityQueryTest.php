<?php

namespace WiserWebSolutions\PDEClient\Tests\Community;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\Community\CommunityQuery;

/**
 * CommunityQuery is a deliberate no-op placeholder - no dataset is wired up
 * yet, so every terminal method must return "nothing" rather than error,
 * regardless of district()/year() context.
 */
class CommunityQueryTest extends TestCase
{
    public function test_get_always_returns_an_empty_collection(): void
    {
        $result = (new CommunityQuery())->district('124157203')->year('2024-2025')->get();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function test_first_always_returns_null(): void
    {
        $this->assertNull((new CommunityQuery())->district('124157203')->first());
    }

    public function test_get_iterator_yields_nothing(): void
    {
        $this->assertSame([], iterator_to_array(new CommunityQuery()));
    }
}
