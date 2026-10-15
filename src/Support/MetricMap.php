<?php

namespace WiserWebSolutions\PDEClient\Support;

use ArrayAccess;
use Countable;
use Error;
use InvalidArgumentException;
use IteratorAggregate;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Partials\PartialsCollection;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Traversable;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\MissingDataBehavior;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

/**
 * Immutable numeric metrics. Literal source keys are never renamed or reindexed.
 *
 * @implements ArrayAccess<array-key, float|null>
 * @implements IteratorAggregate<array-key, float|null>
 */
final class MetricMap extends Data implements ArrayAccess, Countable, IteratorAggregate
{
    private readonly array $values;

    private readonly AvailabilityMetadata $metadata;

    private readonly MissingDataBehavior $behavior;

    /** @param array<array-key, mixed> $values */
    public function __construct(array $values = [], ?AvailabilityMetadata $availability = null)
    {
        foreach ($values as $key => $value) {
            if ($value !== null && ! is_numeric($value)) {
                throw new InvalidArgumentException("Metric [{$key}] must be numeric or null.");
            }
            if ($value !== null && $availability?->state((string) $key, $value) === DataAvailability::NotCollected) {
                throw new InvalidArgumentException("Uncollected metric [{$key}] cannot contain a reported value.");
            }
            $values[$key] = $value === null ? null : (float) $value;
        }
        $this->values = $values;
        $this->metadata = $availability ?? new AvailabilityMetadata;
        $this->behavior = $availability->behavior ?? MissingDataBehavior::configured();
    }

    public function __sleep(): array
    {
        return array_keys(get_object_vars($this));
    }

    /** Preserve existing values, availability policy and projections when a DTO attaches citations. */
    public function withProvenance(DataProvenance $provenance): self
    {
        $copy = new self($this->values, new AvailabilityMetadata($this->metadata->fields, $this->metadata->maps,
            $this->metadata->context, $this->behavior, $provenance->sources === [] ? $this->metadata->provenance : $provenance));
        $copy->_dataContext = $this->_dataContext;

        return $copy;
    }

    public static function from(mixed ...$payloads): static
    {
        $payload = $payloads[0] ?? [];

        return $payload instanceof self ? $payload : new self($payload);
    }

    /** Nested Spatie casts receive the literal metric dictionary, not constructor argument names. */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /** @return list<array-key> */
    public function keys(): array
    {
        return array_keys($this->values + $this->metadata->fields);
    }

    public function availability(string|int $key): DataAvailability
    {
        if (! array_key_exists($key, $this->values) && ! array_key_exists($key, $this->metadata->fields)) {
            throw new InvalidArgumentException("Unknown metric [{$key}].");
        }

        return $this->metadata->state((string) $key, $this->values[$key] ?? null);
    }

    public function hasData(string|int $key): bool
    {
        return $this->availability($key) === DataAvailability::Available;
    }

    /** @return list<SourceReference> */
    public function sourcesFor(string|int $key): array
    {
        $this->availability($key);

        return $this->metadata->provenance->sources ?? [];
    }

    public function get(string|int $key): ?float
    {
        if ($this->availability($key) === DataAvailability::NotCollected && $this->behavior === MissingDataBehavior::ThrowException) {
            $field = ($this->metadata->context['map'] ?? 'metrics')."[{$key}]";
            throw new DataNotCollectedException($field, $this->metadata->context);
        }

        return $this->values[$key] ?? null;
    }

    public function offsetGet(mixed $offset): ?float
    {
        return $this->get($offset);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->get($offset) !== null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new Error('MetricMap is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new Error('MetricMap is immutable.');
    }

    public function count(): int
    {
        return count($this->keys());
    }

    public function getIterator(): Traversable
    {
        foreach ($this->keys() as $key) {
            yield $key => $this->get($key);
        }
    }

    public function transform(null|TransformationContextFactory|TransformationContext $transformationContext = null): array
    {
        $context = match (true) {
            $transformationContext instanceof TransformationContext => $transformationContext,
            $transformationContext instanceof TransformationContextFactory => $transformationContext->get($this),
            default => new TransformationContext,
        };
        $context->mergePartialsFromDataContext($this);
        $keys = $this->keys();

        if ($context->onlyPartials) {
            $selected = $this->selectedKeys($context->onlyPartials, $keys);
            $keys = array_values(array_intersect($keys, $selected));
        }
        if ($context->exceptPartials) {
            $keys = array_values(array_diff($keys, $this->selectedKeys($context->exceptPartials, $keys)));
        }

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    private function selectedKeys(PartialsCollection $partials, array $keys): array
    {
        $selected = [];
        foreach ($partials as $partial) {
            if ($partial->isAll()) {
                return $keys;
            }
            foreach ($partial->getFields() ?? [] as $field) {
                $selected[] = $field;
            }
        }

        return $selected;
    }
}
