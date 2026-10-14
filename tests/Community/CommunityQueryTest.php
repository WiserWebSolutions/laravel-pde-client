<?php

namespace WiserWebSolutions\PDEClient\Tests\Community;

use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\Community\CommunityQuery;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;

class CommunityQueryTest extends TestCase
{
    public function test_get_reports_the_unsupported_dataset(): void
    {
        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('Community datasets are not implemented.');
        (new CommunityQuery)->district('124157203')->year(2024)->get();
    }

    public function test_first_reports_the_unsupported_dataset(): void
    {
        $this->expectException(PDEClientException::class);
        (new CommunityQuery)->district('124157203')->first();
    }

    public function test_iteration_reports_the_unsupported_dataset(): void
    {
        $this->expectException(PDEClientException::class);
        iterator_to_array(new CommunityQuery);
    }
}
