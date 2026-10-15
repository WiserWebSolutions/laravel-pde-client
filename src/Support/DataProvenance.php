<?php

namespace WiserWebSolutions\PDEClient\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Immutable citation registry and references keyed by serialized field name. */
#[MapName(SnakeCaseMapper::class)]
final class DataProvenance extends Data
{
    /** @var list<SourceReference> */
    public readonly array $sources;

    /** @var array<string, list<string>> */
    public readonly array $fieldSources;

    /**
     * @param  list<SourceReference|array>  $sources
     * @param  array<string, list<string>>  $fieldSources
     */
    public function __construct(array $sources = [], array $fieldSources = [])
    {
        $registry = [];
        foreach ($sources as $source) {
            $source = $source instanceof SourceReference ? $source : SourceReference::fromArray($source);
            $registry[$source->id] = $source;
        }
        $fields = [];
        foreach ($fieldSources as $field => $ids) {
            foreach ($ids as $id) {
                if (! isset($registry[$id])) {
                    throw new InvalidArgumentException("Unknown source reference [{$id}].");
                }
            }
            $fields[Str::snake($field)] = array_values(array_unique($ids));
        }
        $this->sources = array_values($registry);
        $this->fieldSources = $fields;
    }

    public static function fromArray(array $data): self
    {
        return new self($data['sources'] ?? [], $data['field_sources'] ?? $data['fieldSources'] ?? []);
    }

    public static function forPath(string $path): self
    {
        $source = SourceReference::forPath($path);

        return new self($source === null ? [] : [$source]);
    }

    /** @param list<SourceReference> $sources @param list<string> $fields */
    public static function forFields(array $sources, array $fields): self
    {
        return new self($sources, array_fill_keys($fields, array_map(fn (SourceReference $source) => $source->id, $sources)));
    }

    /** @return list<SourceReference> */
    public function sourcesFor(string $field): array
    {
        return $this->references($this->fieldSources[Str::snake($field)] ?? []);
    }

    /** @param list<string> $ids @return list<SourceReference> */
    public function references(array $ids): array
    {
        return array_values(array_filter($this->sources, fn (SourceReference $source) => in_array($source->id, $ids, true)));
    }

    /** @param list<SourceReference> $sources */
    public function withField(string $field, array $sources): self
    {
        return new self([...$this->sources, ...$sources], array_replace($this->fieldSources, [
            Str::snake($field) => array_map(fn (SourceReference $source) => $source->id, $sources),
        ]));
    }

    public function merge(self $other): self
    {
        $fields = $this->fieldSources;
        foreach ($other->fieldSources as $field => $ids) {
            $fields[$field] = array_values(array_unique([...($fields[$field] ?? []), ...$ids]));
        }

        return new self([...$this->sources, ...$other->sources], $fields);
    }

    /** @param iterable<object> $records */
    public static function forRecordField(iterable $records, string $field, string $target = 'value'): self
    {
        $sources = [];
        foreach ($records as $record) {
            array_push($sources, ...$record->sourcesFor($field));
        }

        return self::forFields($sources, [$target]);
    }

    /** Summaries inherit their children's references without reading guarded values. */
    public static function forValues(array $values, ?self $base = null): self
    {
        $result = $base ?? new self;
        if ($result->sources !== [] && $result->fieldSources === []) {
            $result = self::forFields($result->sources, array_keys($values));
        }
        foreach ($values as $field => $value) {
            $children = $value instanceof Collection ? $value->all() : [$value];
            $sources = $result->sourcesFor($field);
            foreach ($children as $child) {
                if (! is_object($child) || ! property_exists($child, 'provenance')) {
                    continue;
                }
                array_push($sources, ...$child->provenance->sources);
                foreach (['aun', 'districtName', 'county', 'leaType', 'fiscalYear', 'schoolYear'] as $identity) {
                    if (array_key_exists($identity, $values) && property_exists($child, $identity)
                        && $result->sourcesFor($identity) === []) {
                        $result = $result->withField($identity, $child->sourcesFor($identity));
                    }
                }
            }
            if ($sources !== []) {
                $result = $result->withField($field, $sources);
            }
        }

        $defaults = array_fill_keys(array_map(Str::snake(...), array_keys($values)), []);
        $fields = array_intersect_key($result->fieldSources, $defaults) + $defaults;

        // Spatie projections carry mutable export context; do not share it between DTOs or cached tables.
        $sources = array_map(fn (SourceReference $source) => SourceReference::fromArray($source->toCacheArray()),
            $result->references(array_merge([], ...array_values($fields))));

        return new self($sources, $fields);
    }

    public function toCacheArray(): array
    {
        return ['sources' => array_map(fn (SourceReference $source) => $source->toCacheArray(), $this->sources),
            'field_sources' => $this->fieldSources];
    }
}
