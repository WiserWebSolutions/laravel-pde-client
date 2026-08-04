<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use Illuminate\Contracts\Cache\Repository as Cache;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\AdmParser;
use WiserWebSolutions\PDEClient\FinancialDataElements\Parsing\RealEstateTaxRateParser;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RemembersParsedRowTables;
use WiserWebSolutions\PDEClient\Support\RowTable;

/**
 * Hands the ADM and real estate tax rate query layers parsed RowTables per
 * year, hiding the download-if-missing step and the parsed-table cache -
 * mirrors EnrollmentDataRepository's one-repository-for-several-datasets
 * shape, since ADM and tax rates both live on the same PDE page.
 */
class FinancialDataElementsRepository
{
    use RemembersParsedRowTables;

    public function __construct(
        private readonly FinancialDataElementsFileLocator $locator,
        private readonly AdmParser $admParser,
        private readonly RealEstateTaxRateParser $taxRateParser,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableAdmYears(): array
    {
        return $this->locator->availableAdmYears();
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableTaxRateYears(): array
    {
        return $this->locator->availableTaxRateYears();
    }

    public function admTable(FiscalYear $year): RowTable
    {
        return $this->rememberRowTable(
            "financial-data-elements:adm:{$year->long()}",
            fn () => $this->admParser->parse($this->locator->admWorkbookPath($year)),
        );
    }

    public function taxRateTable(FiscalYear $year): RowTable
    {
        return $this->rememberRowTable(
            "financial-data-elements:tax-rate:{$year->long()}",
            fn () => $this->taxRateParser->parse($this->locator->taxRateWorkbookPath($year)),
        );
    }
}
