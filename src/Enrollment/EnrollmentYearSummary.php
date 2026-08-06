<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One school year's enrollment totals for one LEA, merging every dataset
 * EnrollmentQuery selected (general enrollment, English learners,
 * projections, and - with withEconomicallyDisadvantaged() - economically
 * disadvantaged) into a single per-year record. Produced by
 * EnrollmentQuery::get()/first()/sole() - the year-level view this query
 * returns in place of flat, per-grade EnrollmentRecords, which live on in
 * `grades` for drill-down.
 *
 * Each total is named for the dataset it sums and is always present as a
 * property, but stays null rather than 0 whenever that dataset wasn't part
 * of the query (or simply has no published data for this year) - so null
 * means "not queried/not published", 0.0 means "queried, and PDE reported
 * zero". `economicallyDisadvantagedTotal` follows the same rule, and is
 * further always null unless withEconomicallyDisadvantaged() was called,
 * since PDE doesn't publish that dataset broken out by grade, by English
 * learner status, or as a projection. `economicallyDisadvantaged` carries
 * the rest of that dataset's detail (percent, the enrollment denominator
 * PDE used for it) alongside the same total.
 *
 * `grades` nests every underlying EnrollmentRecord this query matched for
 * the year - across every dataset and actual/projected status merged here -
 * for drill-down into the per-grade detail the totals were summed from.
 *
 * @property Collection<int, EnrollmentRecord> $grades
 */
#[MapName(SnakeCaseMapper::class)]
final class EnrollmentYearSummary extends Data
{
    /**
     * @param  Collection<int, EnrollmentRecord>  $grades
     */
    public function __construct(
        public readonly string $aun,
        public readonly ?string $districtName,
        public readonly ?string $county,
        public readonly ?string $leaType,
        public readonly string $schoolYear,
        public readonly ?float $enrollmentTotal,
        public readonly ?float $projectedEnrollmentTotal,
        public readonly ?float $englishLearnersTotal,
        public readonly ?float $economicallyDisadvantagedTotal,
        public readonly ?EconomicallyDisadvantagedRecord $economicallyDisadvantaged,
        public readonly Collection $grades,
    ) {
    }
}
