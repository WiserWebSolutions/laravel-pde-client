<?php

namespace WiserWebSolutions\PDEClient\Assessment;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One proficiency-result line for one LEA: an exam (PSSA or Keystone), a
 * subject, a tested grade, and a student group, with the percentage of
 * scored students landing in each proficiency band.
 *
 * Percentages are 0-100 as PDE publishes them, and are null when PDE
 * suppressed them (student populations under 11). `grade` is the tested
 * grade as published: '3'-'8' for PSSA, '11' for Keystone, plus a 'Total'
 * row per subject/group aggregating all tested grades.
 */
final class AssessmentRecord implements Arrayable
{
    public const EXAM_PSSA = 'pssa';

    public const EXAM_KEYSTONE = 'keystone';

    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly string $exam,
        public readonly string $subject,
        public readonly string $grade,
        public readonly string $group,
        public readonly ?float $scored,
        public readonly ?float $percentAdvanced,
        public readonly ?float $percentProficient,
        public readonly ?float $percentBasic,
        public readonly ?float $percentBelowBasic,
        public readonly ?float $percentProficientOrAbove,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'county' => $this->county,
            'school_year' => $this->schoolYear,
            'exam' => $this->exam,
            'subject' => $this->subject,
            'grade' => $this->grade,
            'group' => $this->group,
            'scored' => $this->scored,
            'percent_advanced' => $this->percentAdvanced,
            'percent_proficient' => $this->percentProficient,
            'percent_basic' => $this->percentBasic,
            'percent_below_basic' => $this->percentBelowBasic,
            'percent_proficient_or_above' => $this->percentProficientOrAbove,
        ];
    }
}
