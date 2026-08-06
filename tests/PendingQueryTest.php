<?php

namespace WiserWebSolutions\PDEClient\Tests;

use WiserWebSolutions\PDEClient\Assessment\AssessmentQuery;
use WiserWebSolutions\PDEClient\Community\CommunityQuery;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentQuery;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\FinancialQuery;
use WiserWebSolutions\PDEClient\PendingQuery;
use WiserWebSolutions\PDEClient\Personnel\PersonnelQuery;

class PendingQueryTest extends TestCase
{
    public function test_financials_seeds_district_and_year_onto_the_returned_query(): void
    {
        $query = $this->makePending()->district('124157203')->year('2024-2025')->financials();

        $this->assertInstanceOf(FinancialQuery::class, $query);
        $this->assertSame('124157203', $this->seededAun($query));
        $this->assertSame('2024-2025', $this->seededYear($query)->long());
    }

    public function test_enrollments_seeds_district_and_year(): void
    {
        $query = $this->makePending()->district('124157203')->year('2023-2024')->enrollments();

        $this->assertInstanceOf(EnrollmentQuery::class, $query);
        $this->assertSame('124157203', $this->seededAun($query));
        $this->assertSame('2023-2024', $this->seededYear($query)->long());
    }

    public function test_assessments_and_personnel_also_return_the_right_query_type(): void
    {
        $pending = $this->makePending()->district('124157203');

        $this->assertInstanceOf(AssessmentQuery::class, $pending->assessments());
        $this->assertInstanceOf(PersonnelQuery::class, $pending->personnel());
    }

    public function test_community_is_a_no_op_placeholder(): void
    {
        $query = $this->makePending()->district('124157203')->community();

        $this->assertInstanceOf(CommunityQuery::class, $query);
        $this->assertTrue($query->get()->isEmpty());
    }

    public function test_district_falls_back_to_the_configured_default_when_called_with_no_argument(): void
    {
        $query = $this->makePending()->district()->enrollments();

        $this->assertSame('124157203', $this->seededAun($query)); // TestCase's configured default
    }

    public function test_district_throws_when_no_default_is_configured_and_none_given(): void
    {
        config(['pde-client.default_district' => null]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('No district given and no default configured');

        $this->makePending()->district();
    }

    public function test_not_calling_district_or_year_leaves_the_returned_query_unseeded(): void
    {
        $query = $this->makePending()->enrollments();

        $this->assertNull($this->seededAun($query));
        $this->assertNull($this->seededYear($query));
    }

    private function makePending(): PendingQuery
    {
        return new PendingQuery($this->app);
    }

    private function seededAun(object $query): ?string
    {
        return (new \ReflectionProperty($query, 'aun'))->getValue($query);
    }

    private function seededYear(object $query): mixed
    {
        return (new \ReflectionProperty($query, 'year'))->getValue($query);
    }
}
