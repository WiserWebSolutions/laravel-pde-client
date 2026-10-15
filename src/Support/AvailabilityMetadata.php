<?php

namespace WiserWebSolutions\PDEClient\Support;

use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\MissingDataBehavior;

final class AvailabilityMetadata
{
    /**
     * @param  array<string, DataAvailability|string>  $fields
     * @param  array<string, array<array-key, DataAvailability|string>>  $maps
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly array $fields = [],
        public readonly array $maps = [],
        public readonly array $context = [],
        public readonly ?MissingDataBehavior $behavior = null,
        public readonly ?DataProvenance $provenance = null,
    ) {}

    public function state(string $field, mixed $value): DataAvailability
    {
        $state = $this->fields[$field] ?? DataAvailability::forValue($value);

        $state = $state instanceof DataAvailability ? $state : DataAvailability::from($state);

        return $state === DataAvailability::Available ? DataAvailability::forValue($value) : $state;
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        return new self($this->fields, $this->maps, $context + $this->context, $this->behavior, $this->provenance);
    }

    /** @param array<string, DataAvailability|string> $fields */
    public function withFields(array $fields): self
    {
        return new self($fields + $this->fields, $this->maps, $this->context, $this->behavior, $this->provenance);
    }
}
