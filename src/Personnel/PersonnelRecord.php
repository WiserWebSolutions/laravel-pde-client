<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Enums\PersonnelCategory;

/**
 * One staff category's full-time headcount and averages for one LEA in one
 * school year, from PDE's professional staff summary report.
 *
 * `category` Professional is PDE's "PP" TOTAL of the other four
 * (administrator, classroom_teacher, coordinator, other) - summing all five
 * double-counts, same caveat as financial rollup codes.
 *
 * The averages are as PDE publishes them: salary in dollars, service and
 * LEA tenure in years, and education on PDE's numeric attainment scale.
 * Averages missing from older workbooks come through as null.
 */
#[MapName(SnakeCaseMapper::class)]
final class PersonnelRecord extends Data
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $leaName,
        public readonly ?string $leaType,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly PersonnelCategory $category,
        public readonly ?float $count,
        public readonly ?float $femaleCount,
        public readonly ?float $maleCount,
        public readonly ?float $averageSalary,
        public readonly ?float $averageYearsService,
        public readonly ?float $averageYearsInLea,
        public readonly ?float $averageEducationLevel,
    ) {
    }
}
