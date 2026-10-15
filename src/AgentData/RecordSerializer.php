<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use BackedEnum;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\MetricMap;

/** Reads only reported fields; it never changes the application's missing-data policy. */
final class RecordSerializer
{
    public function serialize(object $record, array $sources = [], ?array $fields = null): array
    {
        $values = $availability = [];
        foreach ((new ReflectionClass($record))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getName() === 'provenance') {
                continue;
            }
            $name = $property->getName();
            $key = Str::snake($name);
            if ($fields !== null && ! in_array($key, $fields, true)) {
                continue;
            }
            $state = $record->availability($name);
            $availability[$key] = $state->value;
            $value = $state === DataAvailability::Available ? $record->{$name} : null;
            if ($value instanceof MetricMap) {
                $values[$key] = [];
                foreach ($value->keys() as $metric) {
                    $availability[$key.'.'.$metric] = $value->availability($metric)->value;
                    $values[$key][$metric] = $value->hasData($metric) ? $value->get($metric) : null;
                }
            } else {
                $values[$key] = $value instanceof BackedEnum ? $value->value : $value;
            }
        }

        return ['values' => $values, 'availability' => $availability, 'sources' => SourceReference::unique($sources),
            'provenance' => $record->provenance->toCacheArray()];
    }
}
