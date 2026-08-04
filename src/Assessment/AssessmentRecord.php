<?php

namespace WiserWebSolutions\PDEClient\Assessment;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Enums\Exam;

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
#[MapName(SnakeCaseMapper::class)]
final class AssessmentRecord extends Data
{
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly string $schoolYear,
        public readonly Exam $exam,
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
}
