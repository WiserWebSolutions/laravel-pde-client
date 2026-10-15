<?php

namespace WiserWebSolutions\PDEClient\Support;

use Illuminate\Support\Str;
use WiserWebSolutions\PDEClient\Enums\MissingDataBehavior;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;

/** Plain arrays are portable across Laravel cache drivers. */
final class RowTable
{
    public function __construct(
        public readonly array $districts,
        public readonly array $rows,
        public readonly array $schema = [],
        public readonly array $cells = [],
        public readonly ?string $source = null,
        public readonly array $sources = [],
        public readonly ?DataProvenance $provenance = null,
    ) {}

    /** @param array<string, string> $aliases Source field => DTO property. */
    public function availabilityFor(string $aun, int $index, array $aliases = [], array $context = []): AvailabilityMetadata
    {
        $row = $this->rows[$aun][$index] ?? [];
        $type = isset($row['fund_type'], $row['phase']) ? $row['fund_type'].'|'.$row['phase'] : ($row['category'] ?? $row['group'] ?? '');
        $states = ($this->schema['types'][$type] ?? []) + ($this->schema['fields'] ?? []);
        $overrides = $this->cells[$aun][$index] ?? [];
        $maps = [];
        $schemaMaps = ($this->schema['type_maps'][$type] ?? []) + ($this->schema['maps'] ?? []);
        foreach (($overrides['@maps'] ?? []) + $schemaMaps as $field => $mapStates) {
            $maps[$aliases[$field] ?? Str::camel($field)] = $mapStates + ($schemaMaps[$field] ?? []);
        }
        unset($overrides['@maps']);
        $fields = [];
        foreach ($overrides + $states as $field => $state) {
            $fields[$aliases[$field] ?? Str::camel($field)] = $state;
        }

        $provenance = $this->provenance ?? DataProvenance::forPath($this->source ?? '');
        $sourceFields = [];
        foreach ($provenance->fieldSources as $field => $ids) {
            $sourceFields[$aliases[$field] ?? $field] = $ids;
        }

        return new AvailabilityMetadata($fields, $maps, $context + ['district' => $aun, 'source' => $this->source, 'sources' => $this->sources],
            provenance: new DataProvenance($provenance->sources, $sourceFields));
    }

    /** @return list<SourceReference> */
    public function sourceReferences(): array
    {
        return ($this->provenance ?? DataProvenance::forPath($this->source ?? ''))->sources;
    }

    /** Only complete schema metadata proves a requested group was not collected. */
    public function assertGroupsCollected(array $groups, array $context = []): void
    {
        if (! ($this->schema['groups_complete'] ?? false) || MissingDataBehavior::configured() === MissingDataBehavior::ReturnNull) {
            return;
        }
        $available = array_map(static fn ($group) => Str::lower(trim($group)), $this->schema['groups'] ?? []);
        foreach ($groups as $group) {
            if (! in_array(Str::lower(trim($group)), $available, true)) {
                throw new DataNotCollectedException('group', $context + ['group' => $group, 'source' => $this->source]);
            }
        }
    }

    public function withSources(array $sources): self
    {
        return new self($this->districts, $this->rows, $this->schema, $this->cells, $this->source, $sources, $this->provenance);
    }

    public function toArray(): array
    {
        return ['districts' => $this->districts, 'rows' => $this->rows, 'schema' => $this->schema, 'cells' => $this->cells, 'source' => $this->source, 'sources' => $this->sources,
            'provenance' => $this->provenance?->toCacheArray()];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['districts'], $data['rows'], $data['schema'] ?? [], $data['cells'] ?? [], $data['source'] ?? null, $data['sources'] ?? [],
            isset($data['provenance']) ? DataProvenance::fromArray($data['provenance']) : null);
    }
}
