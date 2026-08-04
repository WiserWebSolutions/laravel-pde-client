<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\RemoteFile;
use WiserWebSolutions\PDEClient\Support\LocalWorkbookStore;

/**
 * Resolves the workbooks the financial query layer needs into local file
 * paths, downloading each one (via the existing finder/downloader machinery,
 * through LocalWorkbookStore) only when it isn't already sitting on the
 * configured disk.
 *
 * The AFR "detailed data" workbooks are keyed by kind rather than year -
 * PDE updates those files in place, adding a tab per year - while GFB
 * publishes one workbook per fiscal year.
 */
class DataFileLocator
{
    /**
     * Maps a detail-workbook kind to the label text that identifies it on
     * the AFR listing page.
     */
    public const AFR_DETAIL_KINDS = [
        'localrev' => 'Local Revenue',
        'staterev' => 'State Revenue',
        'federalrev' => 'Federal Revenue',
        'otherrev' => 'Other Revenue',
        'expdetail' => 'Expenditure Detail',
        'genfundbalance' => 'General Fund Balance',
        'soin' => 'Short- and Long-Term Debt',
    ];

    public function __construct(
        private readonly GfbFinancialData $gfb,
        private readonly AfrFinancialData $afr,
        private readonly LocalWorkbookStore $store,
    ) {
    }

    /**
     * @return list<FiscalYear> fiscal years with a published GFB workbook, newest first
     */
    public function availableGfbYears(): array
    {
        $years = $this->gfb->get()
            ->map(fn (RemoteFile $file) => $file->period)
            ->filter()
            ->map(fn (string $period) => FiscalYear::parse($period))
            ->all();

        usort($years, fn (FiscalYear $a, FiscalYear $b) => $b->startYear <=> $a->startYear);

        return array_values($years);
    }

    public function gfbWorkbookPath(FiscalYear $year): string
    {
        $file = $this->gfb->schoolYear($year->short())->sole();
        $directory = config('pde-client.financial_data.gfb.download_directory', 'pde-client/gfb');

        return $this->store->ensureLocal($file, $directory);
    }

    /**
     * @param  key-of<self::AFR_DETAIL_KINDS>  $kind
     * @param  bool  $refresh  force a re-download (used when a cached copy predates the requested year's tab)
     */
    public function afrDetailWorkbookPath(string $kind, bool $refresh = false): string
    {
        if (! isset(self::AFR_DETAIL_KINDS[$kind])) {
            throw new PDEClientException("Unknown AFR detail workbook kind [{$kind}].");
        }

        $file = $this->afr->matching(self::AFR_DETAIL_KINDS[$kind])->sole();
        $directory = config('pde-client.financial_data.afr.download_directory', 'pde-client/afr');

        return $this->store->ensureLocal($file, $directory, $refresh);
    }
}
