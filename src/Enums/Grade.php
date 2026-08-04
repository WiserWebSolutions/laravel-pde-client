<?php

namespace WiserWebSolutions\PDEClient\Enums;

/**
 * Normalizes PDE's raw grade columns to a single comparable scale: PK, K,
 * 1-12. PDE doesn't publish these consistently across datasets - public
 * enrollment and English learner counts split pre-K into AM/PM/full-day
 * (PKA/PKP/PKF) and kindergarten into 4- and 5-year-old AM/PM/full-day
 * variants (K4A/K4P/K4F/K5A/K5P/K5F), while the projections workbook has
 * just a bare "K" and no pre-K at all. Public enrollment files through
 * 2010-11 (the .xls years) use a simpler "PreK"/"K4"/"K5" split with no
 * AM/PM/full-day breakdown at all. Without normalizing, a merged query
 * across datasets or years couldn't compare kindergarten counts at all.
 */
enum Grade: string
{
    case Pk = 'PK';
    case Kindergarten = 'K';
    case Grade1 = '1';
    case Grade2 = '2';
    case Grade3 = '3';
    case Grade4 = '4';
    case Grade5 = '5';
    case Grade6 = '6';
    case Grade7 = '7';
    case Grade8 = '8';
    case Grade9 = '9';
    case Grade10 = '10';
    case Grade11 = '11';
    case Grade12 = '12';

    private const PK_SUBCODES = [
        'PKA', // Pre-Kindergarten AM
        'PKP', // Pre-Kindergarten PM
        'PKF', // Pre-Kindergarten Full Day
        'PreK', // Pre-Kindergarten Totals
    ];

    private const KINDERGARTEN_SUBCODES = [
        'K4A', // Kindergarten 4 year olds AM
        'K4P', // Kindergarten 4 year olds PM
        'K4F', // Kindergarten 4 year olds Full Day
        'K5A', // Kindergarten 5 year olds AM
        'K5P', // Kindergarten 5 year olds PM
        'K5F', // Kindergarten 5 year olds Full Day
        'K',   // Kindergarten Totals
        'K4',  // Kindergarten 4 year old Totals
        'K5',  // Kindergarten 5 year old Totals
    ];

    /**
     * Normalizes a raw PDE grade code to a comparable string on the PK/K/1-12
     * scale. Returns the trimmed raw code untouched when it isn't one this
     * package recognizes, rather than dropping it, in case PDE adds a column
     * this doesn't expect - so the return type stays `string`, not `self`.
     */
    public static function normalize(string $rawCode): string
    {
        $code = trim($rawCode);

        if (in_array($code, self::PK_SUBCODES, true)) {
            return self::Pk->value;
        }

        if (in_array($code, self::KINDERGARTEN_SUBCODES, true)) {
            return self::Kindergarten->value;
        }

        // "001".."012" -> "1".."12"; leaves anything unrecognized untouched
        // rather than dropping it, in case PDE adds a column this doesn't expect.
        if (preg_match('/^0*(\d{1,2})$/', $code, $matches)) {
            return (string) (int) $matches[1];
        }

        return $code;
    }

    public static function sortIndex(string $grade): int
    {
        $order = array_map(fn (self $case) => $case->value, self::cases());
        $index = array_search($grade, $order, true);

        return $index === false ? count($order) : $index;
    }
}
