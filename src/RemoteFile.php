<?php

namespace WiserWebSolutions\PDEClient;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A single downloadable file discovered on a PDE listing page.
 *
 * Immutable by design — Finders produce these, nothing mutates them
 * afterward. `category` groups files the way the source page visually groups
 * them (e.g. AFR's "Revenues" / "Expenditures" / "Miscellaneous" headings);
 * it's null for sources that don't group their files (e.g. GFB).
 */
final class RemoteFile implements Arrayable
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $category = null,
        public readonly ?string $period = null,
    ) {
    }

    /**
     * The file's basename, URL-decoded (PDE hrefs are percent-encoded, e.g.
     * "afr%202024-2025.xlsx" -> "afr 2024-2025.xlsx").
     */
    public function filename(): string
    {
        return basename(urldecode((string) parse_url($this->url, PHP_URL_PATH)));
    }

    public function extension(): string
    {
        return pathinfo($this->filename(), PATHINFO_EXTENSION);
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'url' => $this->url,
            'category' => $this->category,
            'period' => $this->period,
            'filename' => $this->filename(),
        ];
    }

    /**
     * Rebuilds a RemoteFile from a plain array - used to rehydrate cached
     * results, since AbstractHtmlFinder caches array data rather than objects
     * (see its class docblock for why).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: $data['label'],
            url: $data['url'],
            category: $data['category'] ?? null,
            period: $data['period'] ?? null,
        );
    }
}
