<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FiscalYear;

final class InputValidator
{
    public function validate(array $input, array $properties): array
    {
        $unknown = array_diff(array_keys($input), array_keys($properties));
        if ($unknown !== []) {
            throw new ToolException('invalid_arguments', 'Unsupported arguments: '.implode(', ', $unknown));
        }
        foreach ($properties as $key => $spec) {
            if (! array_key_exists($key, $input)) {
                if ($spec['required'] ?? false) {
                    throw new ToolException('invalid_arguments', "Required argument [{$key}] is missing.");
                }

                continue;
            }
            $this->value($key, $input[$key], $spec);
        }
        ksort($input);

        return $input;
    }

    private function value(string $key, mixed $value, array $spec): void
    {
        $valid = match ($spec['type']) {
            'string' => is_string($value) && trim($value) !== '' && strlen($value) <= 4096,
            'integer' => is_int($value) && $value >= ($spec['minimum'] ?? PHP_INT_MIN) && $value <= ($spec['maximum'] ?? PHP_INT_MAX),
            'array' => is_array($value) && array_is_list($value)
                && count($value) >= ($spec['minItems'] ?? 0) && count($value) <= ($spec['maxItems'] ?? 50),
            default => false,
        };
        if (! $valid || (isset($spec['enum']) && ! in_array($value, $spec['enum'], true))
            || (isset($spec['pattern']) && ! preg_match($spec['pattern'], $value))) {
            throw new ToolException('invalid_arguments', "Invalid argument [{$key}]. Consult the tool schema.");
        }
        if ($spec['type'] === 'array') {
            foreach ($value as $item) {
                $this->value($key.'[]', $item, $spec['items']);
            }
            if (count($value) !== count(array_unique($value, SORT_REGULAR))) {
                throw new ToolException('invalid_arguments', "Duplicate selections in [{$key}] are not allowed.");
            }
        }
        if (($spec['format'] ?? '') === 'auns') {
            foreach ($value as $aun) {
                if (! preg_match('/^\d{9}$/', $aun)) {
                    throw new ToolException('invalid_arguments', 'Every AUN must contain exactly nine digits.');
                }
            }
        }
        if (in_array($spec['format'] ?? '', ['school_year', 'school_years'], true)) {
            foreach ((array) $value as $year) {
                try {
                    FiscalYear::parse($year);
                } catch (PDEClientException|\InvalidArgumentException $exception) {
                    throw new ToolException('invalid_arguments', "Invalid explicit year [{$year}].");
                }
            }
        }
    }
}
