<?php

namespace WiserWebSolutions\PDEClient\Integrations;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** Loaded only by optional adapters, whose packages supply Illuminate JsonSchema. */
final class JsonSchemaBuilder
{
    /** @return array<string, Type> */
    public static function build(JsonSchema $schema, array $properties): array
    {
        return array_map(fn ($spec) => self::type($schema, $spec), $properties);
    }

    private static function type(JsonSchema $schema, array $spec): Type
    {
        $type = match ($spec['type']) {
            'integer' => $schema->integer(),
            'array' => $schema->array()->items(self::type($schema, $spec['items']))->unique(),
            default => $schema->string()->min(1)->max(4096),
        };
        foreach (['minimum' => 'min', 'maximum' => 'max', 'minItems' => 'min', 'maxItems' => 'max'] as $key => $method) {
            if (isset($spec[$key])) {
                $type->{$method}($spec[$key]);
            }
        }
        if (isset($spec['enum'])) {
            $type->enum($spec['enum']);
        }
        if (isset($spec['pattern'])) {
            $type->pattern(trim($spec['pattern'], '/'));
        }

        return $type->description($spec['description'])->required($spec['required'] ?? false);
    }
}
