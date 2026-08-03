<?php

namespace WiserWebSolutions\PDEClient\Personnel;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One staff category's full-time headcount and averages for one LEA in one
 * school year, from PDE's professional staff summary report.
 *
 * `category` 'professional' is PDE's "PP" TOTAL of the other four
 * (administrator, classroom_teacher, coordinator, other) - summing all five
 * double-counts, same caveat as financial rollup codes.
 *
 * The averages are as PDE publishes them: salary in dollars, service and
 * LEA tenure in years, and education on PDE's numeric attainment scale.
 * Averages missing from older workbooks come through as null.
 */
final class PersonnelRecord implements Arrayable
{
    public const CATEGORY_PROFESSIONAL = 'professional';

    public const CATEGORY_ADMINISTRATOR = 'administrator';

    public const CATEGORY_CLASSROOM_TEACHER = 'classroom_teacher';

    public const CATEGORY_COORDINATOR = 'coordinator';

    public const CATEGORY_OTHER = 'other';

    public function __construct(
        public readonly string $aun,
        public readonly ?string $leaName,
        public readonly ?string $leaType,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly string $category,
        public readonly ?float $count,
        public readonly ?float $femaleCount,
        public readonly ?float $maleCount,
        public readonly ?float $averageSalary,
        public readonly ?float $averageYearsService,
        public readonly ?float $averageYearsInLea,
        public readonly ?float $averageEducationLevel,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'lea_name' => $this->leaName,
            'lea_type' => $this->leaType,
            'county' => $this->county,
            'school_year' => $this->schoolYear,
            'category' => $this->category,
            'count' => $this->count,
            'female_count' => $this->femaleCount,
            'male_count' => $this->maleCount,
            'average_salary' => $this->averageSalary,
            'average_years_service' => $this->averageYearsService,
            'average_years_in_lea' => $this->averageYearsInLea,
            'average_education_level' => $this->averageEducationLevel,
        ];
    }
}
