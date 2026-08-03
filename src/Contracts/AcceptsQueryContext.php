<?php

namespace WiserWebSolutions\PDEClient\Contracts;

use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Implemented by every dataset's fluent query (FinancialQuery,
 * EnrollmentQuery, ...) so PendingQuery can seed a freshly resolved query
 * with whatever district/year context was already set, without knowing
 * which concrete query class it's holding.
 */
interface AcceptsQueryContext
{
    /**
     * Selects the LEA by its 9-digit AUN. Called with no argument (or never
     * called), the configured default district applies.
     */
    public function district(?string $aun = null): static;

    /**
     * Accepts '2024-25', '2024-2025', '2024 - 2025', or 2024.
     */
    public function year(string|int|FiscalYear $year): static;
}
