<?php

namespace WiserWebSolutions\PDEClient\Assessment;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Enums\Exam;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;

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
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly string $schoolYear;

    public readonly Exam $exam;

    public readonly string $subject;

    public readonly string $grade;

    public readonly string $group;

    public readonly ?float $scored;

    public readonly ?float $percentAdvanced;

    public readonly ?float $percentProficient;

    public readonly ?float $percentBasic;

    public readonly ?float $percentBelowBasic;

    public readonly ?float $percentProficientOrAbove;

    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        string $schoolYear,
        Exam $exam,
        string $subject,
        string $grade,
        string $group,
        ?float $scored,
        ?float $percentAdvanced,
        ?float $percentProficient,
        ?float $percentBasic,
        ?float $percentBelowBasic,
        ?float $percentProficientOrAbove,
        ?AvailabilityMetadata $availability = null,
        ?DataProvenance $provenance = null,
    ) {
        $values = compact('aun', 'districtName', 'county', 'schoolYear', 'exam', 'subject', 'grade', 'group', 'scored', 'percentAdvanced', 'percentProficient', 'percentBasic', 'percentBelowBasic', 'percentProficientOrAbove');
        $this->provenance = DataProvenance::forValues($values, $provenance ?? $availability?->provenance);
        $this->initializeData($values, $availability);
        $this->aun = $aun;
        if ($this->availability('districtName') === DataAvailability::NotCollected) {
            $this->markUnavailable('districtName');
        } else {
            $this->districtName = $districtName;
        }
        if ($this->availability('county') === DataAvailability::NotCollected) {
            $this->markUnavailable('county');
        } else {
            $this->county = $county;
        }
        $this->schoolYear = $schoolYear;
        $this->exam = $exam;
        $this->subject = $subject;
        $this->grade = $grade;
        $this->group = $group;
        if ($this->availability('scored') === DataAvailability::NotCollected) {
            $this->markUnavailable('scored');
        } else {
            $this->scored = $scored;
        }
        if ($this->availability('percentAdvanced') === DataAvailability::NotCollected) {
            $this->markUnavailable('percentAdvanced');
        } else {
            $this->percentAdvanced = $percentAdvanced;
        }
        if ($this->availability('percentProficient') === DataAvailability::NotCollected) {
            $this->markUnavailable('percentProficient');
        } else {
            $this->percentProficient = $percentProficient;
        }
        if ($this->availability('percentBasic') === DataAvailability::NotCollected) {
            $this->markUnavailable('percentBasic');
        } else {
            $this->percentBasic = $percentBasic;
        }
        if ($this->availability('percentBelowBasic') === DataAvailability::NotCollected) {
            $this->markUnavailable('percentBelowBasic');
        } else {
            $this->percentBelowBasic = $percentBelowBasic;
        }
        if ($this->availability('percentProficientOrAbove') === DataAvailability::NotCollected) {
            $this->markUnavailable('percentProficientOrAbove');
        } else {
            $this->percentProficientOrAbove = $percentProficientOrAbove;
        }
    }
}
