<?php

namespace WiserWebSolutions\PDEClient\Support;

use WiserWebSolutions\PDEClient\Enums\DataAvailability;

final class AmountAccumulator
{
    /** Keeps a missing component from becoming a partial numeric total. */
    public static function add(array &$amounts, array &$availability, string $aun, string $code, mixed $value, bool $sum = true, ?int $precision = null): void
    {
        $state = DataAvailability::forValue($value);
        if ($state === DataAvailability::Available && ! is_numeric($value)) {
            $state = DataAvailability::Unknown;
        }
        $existing = isset($availability[$aun][$code]) ? DataAvailability::from($availability[$aun][$code]) : DataAvailability::Available;
        if ($state !== DataAvailability::Available || ($sum && $existing !== DataAvailability::Available)) {
            $priority = ['not_collected' => 5, 'suppressed' => 4, 'unknown' => 3, 'not_reported' => 2, 'not_applicable' => 1];
            $missing = $sum && ($priority[$existing->value] ?? 0) > ($priority[$state->value] ?? 0) ? $existing : $state;
            $amounts[$aun][$code] = null;
            $availability[$aun][$code] = $missing->value;

            return;
        }
        $amount = ($sum ? ($amounts[$aun][$code] ?? 0.0) : 0.0) + (float) $value;
        $amounts[$aun][$code] = $precision === null ? $amount : round($amount, $precision);
        unset($availability[$aun][$code]);
    }
}
