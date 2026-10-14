<?php

namespace WiserWebSolutions\PDEClient\Exceptions;

final class DataNotCollectedException extends PDEClientException
{
    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $field, public readonly array $context = [])
    {
        $details = array_filter([
            'dataset' => $context['dataset'] ?? null,
            'year' => $context['year'] ?? null,
            'district' => $context['district'] ?? null,
            'group' => $context['group'] ?? null,
            'source' => $context['source'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
        $description = implode(', ', array_map(
            static fn ($key, $value) => "{$key} [{$value}]",
            array_keys($details), array_values($details),
        ));

        parent::__construct("The report did not collect [{$field}]".($description !== '' ? " for {$description}" : '').'.');
    }
}
