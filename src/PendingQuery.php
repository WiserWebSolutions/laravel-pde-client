<?php

namespace WiserWebSolutions\PDEClient;

use Illuminate\Contracts\Container\Container;
use WiserWebSolutions\PDEClient\Assessment\AssessmentQuery;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentQuery;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\FinancialQuery;
use WiserWebSolutions\PDEClient\Graduation\GraduationQuery;
use WiserWebSolutions\PDEClient\Personnel\PersonnelQuery;

/**
 * Shared district/year context ahead of picking a dataset - PDE::district()
 * and PDE::query() both start here. ->financial(), ->enrollments(),
 * ->assessments(), ->graduation(), and ->personnel() branch into the
 * dataset-specific fluent queries, each seeded with whatever district/year
 * was already set:
 *
 *     PDE::district('101260303')->year('2024-2025')->financial()->budget()->revenues();
 *     PDE::district('101260303')->enrollments()->projections(false);
 *     PDE::district('101260303')->assessments()->pssa()->allStudents();
 *
 * district()/year() also exist directly on every dataset query (they all
 * implement AcceptsQueryContext), so picking the dataset first works too:
 * PDE::query()->financial()->district('101260303')->year('2024-25').
 */
class PendingQuery
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Selects the LEA by its 9-digit AUN. Called with no argument (or never
     * called), the configured default district applies once a dataset query
     * actually needs one - if neither this nor a dataset query's own
     * district() is ever called, that query's own default-district fallback
     * applies instead.
     */
    public function district(?string $aun = null): static
    {
        $aun ??= config('pde-client.default_district');

        if ($aun === null || trim((string) $aun) === '') {
            throw new PDEClientException(
                'No district given and no default configured - set pde-client.default_district (PDE_CLIENT_DEFAULT_AUN) or pass an AUN.'
            );
        }

        $this->aun = trim((string) $aun);

        return $this;
    }

    public function year(string|int|FiscalYear $year): static
    {
        $this->year = FiscalYear::parse($year);

        return $this;
    }

    public function financial(): FinancialQuery
    {
        return $this->seed($this->container->make(FinancialQuery::class));
    }

    public function enrollments(): EnrollmentQuery
    {
        return $this->seed($this->container->make(EnrollmentQuery::class));
    }

    public function assessments(): AssessmentQuery
    {
        return $this->seed($this->container->make(AssessmentQuery::class));
    }

    public function graduation(): GraduationQuery
    {
        return $this->seed($this->container->make(GraduationQuery::class));
    }

    public function personnel(): PersonnelQuery
    {
        return $this->seed($this->container->make(PersonnelQuery::class));
    }

    /**
     * @template TQuery of AcceptsQueryContext
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function seed(AcceptsQueryContext $query): AcceptsQueryContext
    {
        if ($this->aun !== null) {
            $query->district($this->aun);
        }

        if ($this->year !== null) {
            $query->year($this->year);
        }

        return $query;
    }
}
