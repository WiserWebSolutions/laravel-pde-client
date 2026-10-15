<?php

namespace WiserWebSolutions\PDEClient\Support;

use WiserWebSolutions\PDEClient\Enums\DataAvailability;

/** Records schema once and only stores exceptional cell states per row. */
final class ReportAvailability
{
    private array $schema = ['fields' => [], 'types' => [], 'maps' => [], 'groups' => [], 'groups_complete' => false];

    private array $cells = [];

    public function __construct(private readonly string $source) {}

    /** @param array<string, int|null> $columns */
    public function define(array $columns, string $type = ''): void
    {
        foreach ($columns as $field => $column) {
            $state = $column === null ? DataAvailability::NotCollected->value : DataAvailability::Available->value;
            if ($type === '') {
                $this->schema['fields'][$field] = $state;
            } else {
                $this->schema['types'][$type][$field] = $state;
            }
        }
    }

    public function states(array $fields, string $type = ''): void
    {
        foreach ($fields as $field => $state) {
            $state = $state instanceof DataAvailability ? $state->value : $state;
            if ($type === '') {
                $this->schema['fields'][$field] = $state;
            } else {
                $this->schema['types'][$type][$field] = $state;
            }
        }
    }

    public function groups(array $groups, bool $complete): void
    {
        $this->schema['groups'] = array_values(array_unique($groups));
        $this->schema['groups_complete'] = $complete;
    }

    public function observe(string $aun, int $index, array $raw, array $columns): void
    {
        foreach ($columns as $field => $column) {
            if ($column === null) {
                continue;
            }
            $value = $raw[$column] ?? null;
            $state = DataAvailability::forValue($value);
            if ($state === DataAvailability::Available && ! in_array($field, ['aun', 'name', 'district_name', 'lea_name', 'county', 'lea_type', 'type', 'subject', 'group', 'grade', 'notes'], true) && ! is_numeric($value)) {
                $state = DataAvailability::Unknown;
            }
            if ($state !== DataAvailability::Available) {
                $this->cells[$aun][$index][$field] = $state->value;
            }
        }
    }

    /** @param array<array-key, mixed> $raw */
    public function map(string $aun, int $index, string $field, array $raw, string $type = ''): void
    {
        foreach ($raw as $key => $value) {
            if ($type === '') {
                $this->schema['maps'][$field][$key] = DataAvailability::Available->value;
            } else {
                $this->schema['type_maps'][$type][$field][$key] = DataAvailability::Available->value;
            }
            $state = DataAvailability::forValue($value);
            if ($state === DataAvailability::Available && ! is_numeric($value)) {
                $state = DataAvailability::Unknown;
            }
            if ($state !== DataAvailability::Available) {
                $this->cells[$aun][$index]['@maps'][$field][$key] = $state->value;
            }
        }
    }

    public function cellStates(string $aun, int $index, array $fields): void
    {
        foreach ($fields as $field => $state) {
            $this->cells[$aun][$index][$field] = $state instanceof DataAvailability ? $state->value : $state;
        }
    }

    public function table(array $districts, array $rows): RowTable
    {
        return new RowTable($districts, $rows, $this->schema, $this->cells, WorkbookFingerprint::source($this->source),
            DataProvenance::forPath($this->source));
    }
}
