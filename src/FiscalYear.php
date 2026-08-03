<?php

namespace WiserWebSolutions\PDEClient;

use InvalidArgumentException;
use Stringable;

/**
 * A PA school fiscal year. PDE is inconsistent about the format: GFB page
 * labels and AFR sheet tabs use "2024-25", workbook snapshots and some file
 * names use "2024-2025", the enrollment projections workbook uses
 * "2024 - 2025" (spaced). This value object accepts any of those (plus a
 * bare start year like "2024") and can render both canonical forms, so the
 * rest of the code never string-wrangles years.
 *
 * Shared across every dataset (financial, enrollment, ...) - PA's fiscal/
 * school year is the same concept regardless of which PDE page the data
 * came from.
 */
final class FiscalYear implements Stringable
{
    private function __construct(public readonly int $startYear)
    {
        if ($startYear < 1900 || $startYear > 2200) {
            throw new InvalidArgumentException("Implausible fiscal year start [{$startYear}].");
        }
    }

    public static function parse(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $value = trim((string) $value);

        // "2024-25", "2024-2025", "2024 - 2025", or bare "2024" - always keyed by start year.
        if (preg_match('/^(\d{4})(?:\s*-\s*\d{2}(?:\d{2})?)?$/', $value, $matches)) {
            return new self((int) $matches[1]);
        }

        throw new InvalidArgumentException(
            "Unrecognized fiscal year [{$value}]; expected formats like '2024-25' or '2024-2025'."
        );
    }

    /** "2024-25" - as used by GFB page labels and AFR sheet tabs. */
    public function short(): string
    {
        return $this->startYear.'-'.substr((string) ($this->startYear + 1), -2);
    }

    /** "2024-2025" - the canonical long form. */
    public function long(): string
    {
        return $this->startYear.'-'.($this->startYear + 1);
    }

    public function equals(self $other): bool
    {
        return $this->startYear === $other->startYear;
    }

    public function __toString(): string
    {
        return $this->long();
    }
}
