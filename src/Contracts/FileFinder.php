<?php

namespace WiserWebSolutions\PDEClient\Contracts;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\RemoteFile;

/**
 * Discovers downloadable files published on a PDE listing page.
 * Implementations own fetching + parsing one specific page; nothing about
 * "which files currently exist" is assumed by callers.
 */
interface FileFinder
{
    /**
     * @return Collection<int, RemoteFile>
     */
    public function find(): Collection;
}
