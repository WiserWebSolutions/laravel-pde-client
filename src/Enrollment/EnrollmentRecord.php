<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One grade's enrollment count for one LEA in one school year, from one of
 * PDE's enrollment datasets (general enrollment or English learners),
 * actual or projected.
 *
 * `grade` is normalized to PK/K/1-12 (see Grade::normalize()); `subCounts`
 * keeps the raw published columns that were summed into it (e.g. a
 * kindergarten record's subCounts might be ['K5A' => 8, 'K5F' => 120]) for
 * callers that need the AM/PM/full-day breakdown PDE actually reports.
 */
final class EnrollmentRecord implements Arrayable
{
    public const DATASET_ENROLLMENT = 'enrollment';

    public const DATASET_ENGLISH_LEARNERS = 'english_learners';

    /**
     * @param  array<string, float>  $subCounts
     */
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly ?string $leaType,
        public readonly string $schoolYear,
        public readonly string $dataset,
        public readonly bool $isProjection,
        public readonly string $grade,
        public readonly float $count,
        public readonly array $subCounts,
    ) {
    }

    public function toArray(): array
    {
        return [
            'aun' => $this->aun,
            'district_name' => $this->districtName,
            'county' => $this->county,
            'lea_type' => $this->leaType,
            'school_year' => $this->schoolYear,
            'dataset' => $this->dataset,
            'is_projection' => $this->isProjection,
            'grade' => $this->grade,
            'count' => $this->count,
            'sub_counts' => $this->subCounts,
        ];
    }
}
