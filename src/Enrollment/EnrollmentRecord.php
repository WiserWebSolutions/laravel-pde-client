<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One grade's enrollment counts for one LEA in one school year, merging
 * every dataset EnrollmentQuery selected (general enrollment, projections,
 * and English learners) into a single row per grade rather than one row per
 * dataset - so a grade queried with withEnglishLearners() gets one
 * EnrollmentRecord with both `count` and `englishLearnersCount` populated,
 * not two separate records that happen to share a `grade`.
 *
 * Each count is named for the dataset it holds and is always present as a
 * property, but stays null (with its matching subCounts empty) whenever
 * that dataset wasn't part of the query or simply has no published data for
 * this grade/year - so null means "not queried/not published", 0.0 means
 * "queried, and PDE reported zero".
 *
 * `grade` is normalized to PK/K/1-12 (see Enums\Grade::normalize()) - kept as
 * a plain string rather than the Grade enum since an unrecognized raw PDE
 * code passes through untouched instead of being dropped; each `subCounts`
 * map keeps the raw published columns summed into its matching count (e.g. a
 * kindergarten record's subCounts might be ['K5A' => 8, 'K5F' => 120]) for
 * callers that need the AM/PM/full-day breakdown PDE actually reports.
 */
#[MapName(SnakeCaseMapper::class)]
final class EnrollmentRecord extends Data
{
    /**
     * @param  array<string, float>  $subCounts
     * @param  array<string, float>  $projectedSubCounts
     * @param  array<string, float>  $englishLearnersSubCounts
     */
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly ?string $leaType,
        public readonly string $schoolYear,
        public readonly string $grade,
        public readonly ?float $count,
        public readonly array $subCounts,
        public readonly ?float $projectedCount,
        public readonly array $projectedSubCounts,
        public readonly ?float $englishLearnersCount,
        public readonly array $englishLearnersSubCounts,
    ) {
    }
}
