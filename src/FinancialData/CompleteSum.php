<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use WiserWebSolutions\PDEClient\Enums\DataAvailability;

final class CompleteSum
{
    /** @return array{0: float|null, 1: DataAvailability} */
    public static function calculate(array $values, array $states = []): array
    {
        $missing = null;
        foreach ($values as $key => $value) {
            $state = $states[$key] ?? DataAvailability::forValue($value);
            $state = $state instanceof DataAvailability ? $state : DataAvailability::from($state);
            if ($state !== DataAvailability::Available && ($missing === null || $state->missingPriority() > $missing->missingPriority())) {
                $missing = $state;
            }
        }

        return $missing !== null ? [null, $missing] : ($values === [] ? [null, DataAvailability::NotReported] : [round(array_sum($values), 2), DataAvailability::Available]);
    }
}
