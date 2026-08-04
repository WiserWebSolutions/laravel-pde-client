<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Enums\EnrollmentDataset;

/**
 * One grade's enrollment count for one LEA in one school year, from one of
 * PDE's enrollment datasets (general enrollment or English learners),
 * actual or projected.
 *
 * `grade` is normalized to PK/K/1-12 (see Enums\Grade::normalize()) - kept as
 * a plain string rather than the Grade enum since an unrecognized raw PDE
 * code passes through untouched instead of being dropped; `subCounts` keeps
 * the raw published columns that were summed into it (e.g. a kindergarten
 * record's subCounts might be ['K5A' => 8, 'K5F' => 120]) for callers that
 * need the AM/PM/full-day breakdown PDE actually reports.
 */
#[MapName(SnakeCaseMapper::class)]
final class EnrollmentRecord extends Data
{
    /**
     * @param  array<string, float>  $subCounts
     */
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly ?string $leaType,
        public readonly string $schoolYear,
        public readonly EnrollmentDataset $dataset,
        public readonly bool $isProjection,
        public readonly string $grade,
        public readonly float $count,
        public readonly array $subCounts,
    ) {
    }
}
