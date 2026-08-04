<?php

namespace WiserWebSolutions\PDEClient\FinancialDataElements;

use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FinancialDataElements\Finders\FinancialDataElementsFinder;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;

/**
 * Resolves the workbooks the ADM and real estate tax rate queries need into
 * local file paths - both publish one workbook per school year, mirroring
 * EnrollmentFileLocator.
 */
class FinancialDataElementsFileLocator
{
    public function __construct(
        private readonly FinancialDataElementsFinder $finder,
        private readonly FileDownloader $downloader,
        private readonly LocalWorkbookStore $store,
    ) {
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableAdmYears(): array
    {
        return $this->availableYears('average_daily_membership');
    }

    /**
     * @return list<FiscalYear> newest first
     */
    public function availableTaxRateYears(): array
    {
        return $this->availableYears('real_estate_tax_rates');
    }

    public function admWorkbookPath(FiscalYear $year): string
    {
        return $this->store->ensureLocal(
            $this->fileForYear('average_daily_membership', $year),
            $this->directory(),
        );
    }

    public function taxRateWorkbookPath(FiscalYear $year): string
    {
        return $this->store->ensureLocal(
            $this->fileForYear('real_estate_tax_rates', $year),
            $this->directory(),
        );
    }

    private function fileForYear(string $category, FiscalYear $year): RemoteFile
    {
        $file = $this->files()->category($category)->get()
            ->first(fn (RemoteFile $file) => $file->period === $year->long());

        if ($file === null) {
            throw DataSetNotFoundException::noneMatched(
                "category [{$category}] and year [{$year->long()}]"
            );
        }

        return $file;
    }

    /**
     * @return list<FiscalYear> newest first
     */
    private function availableYears(string $category): array
    {
        $years = $this->files()->category($category)->get()
            ->map(fn (RemoteFile $file) => $file->period)
            ->filter()
            ->map(fn (string $period) => FiscalYear::parse($period))
            ->all();

        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return array_values($years);
    }

    private function files(): FinancialDataElementsFiles
    {
        return new FinancialDataElementsFiles($this->finder, $this->downloader);
    }

    private function directory(): string
    {
        return config('pde-client.financial_data_elements.download_directory', 'pde-client/financial-data-elements');
    }
}
