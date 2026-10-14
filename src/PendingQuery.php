<?php

namespace WiserWebSolutions\PDEClient;

use Illuminate\Contracts\Container\Container;
use WiserWebSolutions\PDEClient\Assessment\AssessmentQuery;
use WiserWebSolutions\PDEClient\Community\CommunityQuery;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentQuery;
use WiserWebSolutions\PDEClient\FinancialData\FinancialQuery;
use WiserWebSolutions\PDEClient\Personnel\PersonnelQuery;

/**
 * Shared district/year context ahead of picking a category - PDE::district()
 * and PDE::query() both start here. ->financials(), ->enrollments(),
 * ->assessments(), ->personnel(), and ->community() branch into the five
 * dataset categories, each seeded with whatever district/year was already
 * set. Every category beyond its primary dataset is reached through a
 * sub-method on that category's query (e.g. financials()->fundBalance()) -
 * see each category's own docblock for its full list.
 *
 *     PDE::district('101260303')->year('2024-2025')->financials()->budget()->revenues();
 *     PDE::district('101260303')->enrollments()->withProjections();
 *     PDE::district('101260303')->assessments()->pssa()->allStudents();
 *     PDE::district('101260303')->enrollments()->averageDailyMembership()->sole();
 *     PDE::district('101260303')->financials()->fundBalance()->get();
 *     PDE::district('101260303')->assessments()->graduation()->get();
 *
 * district()/year() also exist directly on every dataset query (they all
 * implement AcceptsQueryContext), so picking the category first works too:
 * PDE::query()->financials()->district('101260303')->year('2024-25').
 */
class PendingQuery
{
    use HasQueryContext;

    public function __construct(private readonly Container $container) {}

    public function financials(): FinancialQuery
    {
        return $this->makeSibling($this->container, FinancialQuery::class);
    }

    public function enrollments(): EnrollmentQuery
    {
        return $this->makeSibling($this->container, EnrollmentQuery::class);
    }

    public function assessments(): AssessmentQuery
    {
        return $this->makeSibling($this->container, AssessmentQuery::class);
    }

    public function personnel(): PersonnelQuery
    {
        return $this->makeSibling($this->container, PersonnelQuery::class);
    }

    /** Placeholder category - no dataset wired up yet, see CommunityQuery. */
    public function community(): CommunityQuery
    {
        return $this->makeSibling($this->container, CommunityQuery::class);
    }
}
