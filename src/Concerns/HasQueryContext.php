<?php

namespace WiserWebSolutions\PDEClient\Concerns;

use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Shared district()/year()/get()/first() plumbing for every dataset query
 * (FinancialQuery, EnrollmentQuery, ...), so each one only has to implement
 * its own get() and whatever dataset-specific filters it needs.
 *
 * year() defaults to the single most recent year available - explicit
 * year('2024-2025') pins to one year, and allYears()/years()/year('all')
 * opt back into "every year available", the old default.
 */
trait HasQueryContext
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    /** @var 'recent'|'all'|'explicit' */
    private string $yearMode = 'recent';

    /**
     * Selects the LEA by its 9-digit AUN. Called with no argument (or never
     * called), the configured default district applies.
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

    /**
     * Accepts '2024-25', '2024-2025', '2024 - 2025', or 2024, pinning the
     * query to that single year. Called with no argument (or the string
     * 'recent'), it resolves to the single most recent year available -
     * the default when year() is never called at all. The string 'all' is
     * an alias for allYears().
     */
    public function year(string|int|FiscalYear|null $year = null): static
    {
        if ($year === null || (is_string($year) && strtolower(trim($year)) === 'recent')) {
            $this->yearMode = 'recent';
            $this->year = null;

            return $this;
        }

        if (is_string($year) && strtolower(trim($year)) === 'all') {
            return $this->allYears();
        }

        $this->yearMode = 'explicit';
        $this->year = FiscalYear::parse($year);

        return $this;
    }

    /** Every year available, instead of just the most recent. */
    public function allYears(): static
    {
        $this->yearMode = 'all';
        $this->year = null;

        return $this;
    }

    /** Alias for allYears(). */
    public function years(): static
    {
        return $this->allYears();
    }

    protected function resolveAun(): string
    {
        if ($this->aun === null) {
            $this->district();
        }

        return $this->aun;
    }

    /**
     * Applies the current year selection to a repository's full list of
     * available years (any order, possibly with duplicate start years) -
     * explicit -> just that year; all -> every available year, newest
     * first; recent (the default) -> just the newest.
     *
     * @param  list<FiscalYear>  $available
     * @return list<FiscalYear>
     */
    protected function selectYears(array $available): array
    {
        if ($this->yearMode === 'explicit') {
            return [$this->year];
        }

        $byStart = [];

        foreach ($available as $year) {
            $byStart[$year->startYear] = $year;
        }

        krsort($byStart);
        $sorted = array_values($byStart);

        if ($this->yearMode === 'all') {
            return $sorted;
        }

        return $sorted === [] ? [] : [$sorted[0]];
    }

    /**
     * Pushes this query's district/year context onto a freshly resolved
     * sibling query (e.g. FinancialQuery -> FundBalanceQuery), so
     * ->financials()->fundBalance() carries over whatever district()/year()
     * was already set on the financials() query.
     *
     * @template TQuery of AcceptsQueryContext
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function seedSibling(AcceptsQueryContext $query): AcceptsQueryContext
    {
        if ($this->aun !== null) {
            $query->district($this->aun);
        }

        match ($this->yearMode) {
            'explicit' => $query->year($this->year),
            'all' => $query->allYears(),
            default => null,
        };

        return $query;
    }
}
