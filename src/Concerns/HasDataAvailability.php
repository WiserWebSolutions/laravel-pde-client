<?php

namespace WiserWebSolutions\PDEClient\Concerns;

use Error;
use InvalidArgumentException;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\MissingDataBehavior;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\MetricMap;
use WiserWebSolutions\PDEClient\Support\Transformation\AvailabilityTransformedDataResolver;

trait HasDataAvailability
{
    /** @var array<string, DataAvailability> */
    private array $fieldAvailability = [];

    private AvailabilityMetadata $availabilityMetadata;

    private MissingDataBehavior $missingDataBehavior;

    /** Called in the declaring DTO's scope, before readonly initialization. */
    private function initializeData(array $values, ?AvailabilityMetadata $availability = null): void
    {
        $context = array_filter([
            'dataset' => static::class,
            'district' => $values['aun'] ?? null,
            'year' => $values['schoolYear'] ?? $values['fiscalYear'] ?? null,
            'group' => $values['group'] ?? null,
        ], static fn ($value) => $value !== null);
        $this->availabilityMetadata = ($availability ?? new AvailabilityMetadata)->withContext(($availability->context ?? []) + $context);
        $this->missingDataBehavior = $availability->behavior ?? MissingDataBehavior::configured();

        foreach ($values as $field => $value) {
            $state = $this->availabilityMetadata->state($field, $value);
            if ($state === DataAvailability::NotCollected && $value !== null && $value !== []) {
                throw new InvalidArgumentException("Uncollected property [{$field}] cannot contain a reported value.");
            }
            $this->fieldAvailability[$field] = $state;
        }
    }

    /** PHP's readonly lazy pattern: unset before the first assignment. */
    private function markUnavailable(string $property): void
    {
        unset($this->{$property});
    }

    private function metricMap(string $field, array|MetricMap|null $value): ?MetricMap
    {
        if ($value instanceof MetricMap || $value === null) {
            return $value;
        }

        return new MetricMap($value, new AvailabilityMetadata(
            fields: $this->availabilityMetadata->maps[$field] ?? [],
            context: $this->availabilityMetadata->context + ['map' => $field],
            behavior: $this->missingDataBehavior,
        ));
    }

    public function availability(string $property): DataAvailability
    {
        return $this->fieldAvailability[$property]
            ?? throw new InvalidArgumentException('Unknown data property ['.$property.'] on '.static::class.'.');
    }

    /** @internal Carries source context into derived DTO exceptions. */
    public function availabilityContext(): array
    {
        return $this->availabilityMetadata->context;
    }

    public function hasData(string $property): bool
    {
        return $this->availability($property) === DataAvailability::Available;
    }

    public function __get(string $property): mixed
    {
        if (($this->fieldAvailability[$property] ?? null) !== DataAvailability::NotCollected) {
            throw new Error('Unknown or uninitialized property '.static::class.'::$'.$property.'.');
        }

        if ($this->missingDataBehavior === MissingDataBehavior::ThrowException) {
            throw new DataNotCollectedException($property, $this->availabilityMetadata->context);
        }

        return null;
    }

    public function __isset(string $property): bool
    {
        if (! array_key_exists($property, $this->fieldAvailability)) {
            throw new Error('Unknown data property '.static::class.'::$'.$property.'.');
        }

        return $this->__get($property) !== null;
    }

    public function __unset(string $property): void
    {
        throw new Error('Cannot unset readonly property '.static::class.'::$'.$property.'.');
    }

    public function __sleep(): array
    {
        return array_keys(get_object_vars($this));
    }

    /** Restore lazy reads after native PHP cache/queue serialization. */
    public function __wakeup(): void
    {
        foreach ($this->fieldAvailability as $field => $state) {
            if ($state === DataAvailability::NotCollected) {
                $this->markUnavailable($field);
            }
        }
    }

    /** @internal Only supplies initialized fields to Spatie's visibility resolver. */
    public function availabilitySnapshot(): static
    {
        $snapshot = clone $this;

        foreach ($this->fieldAvailability as $field => $state) {
            if ($state === DataAvailability::NotCollected) {
                $snapshot->{$field} = null;
            }
        }

        return $snapshot;
    }

    public function transform(null|TransformationContextFactory|TransformationContext $transformationContext = null): array
    {
        $context = match (true) {
            $transformationContext instanceof TransformationContext => $transformationContext,
            $transformationContext instanceof TransformationContextFactory => $transformationContext->get($this),
            default => new TransformationContext(
                maxDepth: config('data.max_transformation_depth'),
                throwWhenMaxDepthReached: config('data.throw_when_max_transformation_depth_reached', true),
            ),
        };
        $context->mergePartialsFromDataContext($this);

        return app(AvailabilityTransformedDataResolver::class)->execute($this, $context);
    }
}
