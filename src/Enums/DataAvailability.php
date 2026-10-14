<?php

namespace WiserWebSolutions\PDEClient\Enums;

enum DataAvailability: string
{
    case Available = 'available';
    case NotCollected = 'not_collected';
    case Suppressed = 'suppressed';
    case NotReported = 'not_reported';
    case NotRequested = 'not_requested';
    case NotApplicable = 'not_applicable';
    case Unknown = 'unknown';

    public function missingPriority(): int
    {
        return match ($this) {
            self::NotCollected => 5, self::Suppressed => 4, self::Unknown => 3,
            self::NotReported => 2, self::NotApplicable => 1, default => 0,
        };
    }

    public static function forValue(mixed $value): self
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return self::NotReported;
        }

        if (is_string($value) && preg_match('/^(?:\*+|<\s*11|[sS])$/', trim($value))) {
            return self::Suppressed;
        }

        if (is_string($value) && in_array(strtolower(trim($value)), ['n/a', 'na', 'not applicable'], true)) {
            return self::NotApplicable;
        }

        return self::Available;
    }
}
