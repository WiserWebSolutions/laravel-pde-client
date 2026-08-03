<?php

namespace WiserWebSolutions\PDEClient;

use Illuminate\Contracts\Container\Container;
use WiserWebSolutions\PDEClient\Assessment\AssessmentFiles;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentFiles;
use WiserWebSolutions\PDEClient\FinancialData\AfrFinancialData;
use WiserWebSolutions\PDEClient\FinancialData\GfbFinancialData;
use WiserWebSolutions\PDEClient\Graduation\GraduationFiles;
use WiserWebSolutions\PDEClient\Personnel\PersonnelFiles;

/**
 * Backs the PDE facade. Each call resolves a fresh source/query instance
 * from the container (like Model::query() returning a fresh builder) since
 * filter state accumulates per chain and must not leak between unrelated
 * calls.
 *
 * The *Files() accessors are raw file discovery+download, one per PDE
 * listing page, independent of any dataset. query()/district() start a
 * PendingQuery, which branches into a parsed dataset query via
 * ->financial(), ->enrollments(), ->assessments(), ->graduation(), or
 * ->personnel().
 */
class PDEClientManager
{
    public function __construct(private readonly Container $container)
    {
    }

    /** File discovery/downloads for GFB budget workbooks. */
    public function gfb(): GfbFinancialData
    {
        return $this->container->make(GfbFinancialData::class);
    }

    /** File discovery/downloads for AFR actuals workbooks. */
    public function afr(): AfrFinancialData
    {
        return $this->container->make(AfrFinancialData::class);
    }

    /** File discovery/downloads for public enrollment, projections, and English learner workbooks. */
    public function enrollmentFiles(): EnrollmentFiles
    {
        return $this->container->make(EnrollmentFiles::class);
    }

    /** File discovery/downloads for PSSA and Keystone results workbooks. */
    public function assessmentFiles(): AssessmentFiles
    {
        return $this->container->make(AssessmentFiles::class);
    }

    /** File discovery/downloads for cohort graduation rate and dropout workbooks. */
    public function graduationFiles(): GraduationFiles
    {
        return $this->container->make(GraduationFiles::class);
    }

    /** File discovery/downloads for staff summary, individual staff, and vacancy workbooks. */
    public function personnelFiles(): PersonnelFiles
    {
        return $this->container->make(PersonnelFiles::class);
    }

    /** A fresh district/year context with no dataset chosen yet. */
    public function query(): PendingQuery
    {
        return $this->container->make(PendingQuery::class);
    }

    /**
     * Shortcut: a fresh context scoped to the given district (or the
     * configured default when called with no argument).
     */
    public function district(?string $aun = null): PendingQuery
    {
        return $this->query()->district($aun);
    }
}
