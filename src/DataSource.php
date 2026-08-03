<?php

namespace WiserWebSolutions\PDEClient;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Contracts\FileFinder;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;

/**
 * Fluent entry point over one PDE listing page (GFB, AFR, enrollment, ...).
 * Filters accumulate via chained calls and are only applied when a terminal
 * method (get/first/sole/download) runs — the same shape as Laravel's own
 * query builders.
 *
 * A new listing page becomes a new subclass: implement defaultDirectory()
 * and, if useful, a few named convenience filters (see GfbFinancialData's
 * schoolYear()/latest() or AfrFinancialData's revenues()/expenditures()).
 * Nothing about filtering, caching, or downloading needs to be repeated.
 */
abstract class DataSource
{
    /** @var Collection<int, RemoteFile>|null */
    protected ?Collection $files = null;

    protected ?string $categoryFilter = null;

    protected ?string $labelFilter = null;

    public function __construct(
        protected readonly FileFinder $finder,
        protected readonly FileDownloader $downloader,
    ) {
    }

    /**
     * Restrict to files whose category matches exactly (case-insensitive).
     * Meaningless for sources whose Finder never sets a category.
     */
    public function category(string $category): static
    {
        $this->categoryFilter = $category;

        return $this;
    }

    /**
     * Restrict to files whose label contains the given text (case-insensitive).
     */
    public function matching(string $needle): static
    {
        $this->labelFilter = $needle;

        return $this;
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    public function get(): Collection
    {
        return $this->applyFilters($this->allFiles());
    }

    public function first(): ?RemoteFile
    {
        return $this->get()->first();
    }

    /**
     * Like get()->first(), but fails loudly when the current filters don't
     * resolve to exactly one file - useful when a caller means "the" file,
     * not "whichever one happens to be first".
     */
    public function sole(): RemoteFile
    {
        $matches = $this->get();

        return match (true) {
            $matches->isEmpty() => throw DataSetNotFoundException::noneMatched($this->filterDescription()),
            $matches->count() > 1 => throw DataSetNotFoundException::multipleMatched($this->filterDescription(), $matches->count()),
            default => $matches->first(),
        };
    }

    /**
     * Downloads every file currently matched by the active filters.
     *
     * @return Collection<int, string> stored paths, in the same order as get()
     */
    public function download(?string $directory = null, ?string $disk = null): Collection
    {
        $directory ??= $this->defaultDirectory();

        return $this->get()->map(
            fn (RemoteFile $file) => $this->downloader->download(
                $file,
                rtrim($directory, '/').'/'.$file->filename(),
                $disk,
            )
        );
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    protected function allFiles(): Collection
    {
        return $this->files ??= $this->finder->find();
    }

    /**
     * @param  Collection<int, RemoteFile>  $files
     * @return Collection<int, RemoteFile>
     */
    protected function applyFilters(Collection $files): Collection
    {
        return $files
            ->when(
                $this->categoryFilter !== null,
                fn (Collection $files) => $files->filter(
                    fn (RemoteFile $file) => Str::lower((string) $file->category) === Str::lower($this->categoryFilter)
                )
            )
            ->when(
                $this->labelFilter !== null,
                fn (Collection $files) => $files->filter(
                    fn (RemoteFile $file) => Str::contains($file->label, $this->labelFilter, ignoreCase: true)
                )
            )
            ->values();
    }

    protected function filterDescription(): string
    {
        $parts = array_filter([
            $this->categoryFilter !== null ? "category [{$this->categoryFilter}]" : null,
            $this->labelFilter !== null ? "label containing [{$this->labelFilter}]" : null,
        ]);

        return $parts === [] ? 'no filters applied' : implode(' and ', $parts);
    }

    /**
     * Default download() directory when the caller doesn't specify one.
     */
    abstract protected function defaultDirectory(): string;
}
