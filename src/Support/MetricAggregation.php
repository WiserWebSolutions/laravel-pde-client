<?php

namespace WiserWebSolutions\PDEClient\Support;

use WiserWebSolutions\PDEClient\Enums\DataAvailability;

final class MetricAggregation
{
    /** @return array{0: float|null, 1: DataAvailability} */
    public static function sum(iterable $records, string $property, ?string $mapProperty = null): array
    {
        $sum = 0.0;
        $present = false;
        $missing = null;
        $absent = false;
        $priority = ['not_collected' => 5, 'suppressed' => 4, 'unknown' => 3, 'not_reported' => 2];
        foreach ($records as $record) {
            $state = $record->availability($property);
            if ($mapProperty !== null && $state === DataAvailability::NotReported && $record->availability($mapProperty) !== DataAvailability::NotCollected && count($record->{$mapProperty} ?? []) === 0) {
                $absent = true;

                continue;
            }
            if ($state === DataAvailability::NotRequested || $state === DataAvailability::NotApplicable) {
                continue;
            }
            if ($state !== DataAvailability::Available) {
                if ($missing === null || ($priority[$state->value] ?? 0) > ($priority[$missing->value] ?? 0)) {
                    $missing = $state;
                }

                continue;
            }
            $present = true;
            $sum += $record->{$property};
        }

        return $missing !== null ? [null, $missing] : ($present ? [$sum, DataAvailability::Available] : [null, $absent ? DataAvailability::NotReported : DataAvailability::NotRequested]);
    }
}
