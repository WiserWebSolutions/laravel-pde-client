<?php

namespace WiserWebSolutions\PDEClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \WiserWebSolutions\PDEClient\FinancialData\GfbFinancialData gfb()
 * @method static \WiserWebSolutions\PDEClient\FinancialData\AfrFinancialData afr()
 * @method static \WiserWebSolutions\PDEClient\Enrollment\EnrollmentFiles enrollmentFiles()
 * @method static \WiserWebSolutions\PDEClient\Assessment\AssessmentFiles assessmentFiles()
 * @method static \WiserWebSolutions\PDEClient\Graduation\GraduationFiles graduationFiles()
 * @method static \WiserWebSolutions\PDEClient\Personnel\PersonnelFiles personnelFiles()
 * @method static \WiserWebSolutions\PDEClient\PendingQuery query()
 * @method static \WiserWebSolutions\PDEClient\PendingQuery district(?string $aun = null)
 *
 * @see \WiserWebSolutions\PDEClient\PDEClientManager
 */
class PDE extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'pde-client';
    }
}
