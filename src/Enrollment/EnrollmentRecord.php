<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\MetricMap;

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
    use HasDataAvailability;

    public readonly string $aun;

    public readonly ?string $districtName;

    public readonly ?string $county;

    public readonly ?string $leaType;

    public readonly string $schoolYear;

    public readonly string $grade;

    public readonly ?float $count;

    public readonly ?MetricMap $subCounts;

    public readonly ?float $projectedCount;

    public readonly ?MetricMap $projectedSubCounts;

    public readonly ?float $englishLearnersCount;

    public readonly ?MetricMap $englishLearnersSubCounts;

    /**
     * @param  array<string, float>  $subCounts
     * @param  array<string, float>  $projectedSubCounts
     * @param  array<string, float>  $englishLearnersSubCounts
     */
    public function __construct(
        string $aun,
        ?string $districtName,
        ?string $county,
        ?string $leaType,
        string $schoolYear,
        string $grade,
        ?float $count,
        array|MetricMap|null $subCounts,
        ?float $projectedCount,
        array|MetricMap|null $projectedSubCounts,
        ?float $englishLearnersCount,
        array|MetricMap|null $englishLearnersSubCounts,
        ?AvailabilityMetadata $availability = null,
        ?DataProvenance $provenance = null,
    ) {
        $values = compact('aun', 'districtName', 'county', 'leaType', 'schoolYear', 'grade', 'count', 'subCounts', 'projectedCount', 'projectedSubCounts', 'englishLearnersCount', 'englishLearnersSubCounts');
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
        if ($this->availability('leaType') === DataAvailability::NotCollected) {
            $this->markUnavailable('leaType');
        } else {
            $this->leaType = $leaType;
        }
        $this->schoolYear = $schoolYear;
        $this->grade = $grade;
        if ($this->availability('count') === DataAvailability::NotCollected) {
            $this->markUnavailable('count');
        } else {
            $this->count = $count;
        }
        if ($this->availability('subCounts') === DataAvailability::NotCollected) {
            $this->markUnavailable('subCounts');
        } else {
            $this->subCounts = $this->metricMap('subCounts', $subCounts);
        }
        if ($this->availability('projectedCount') === DataAvailability::NotCollected) {
            $this->markUnavailable('projectedCount');
        } else {
            $this->projectedCount = $projectedCount;
        }
        if ($this->availability('projectedSubCounts') === DataAvailability::NotCollected) {
            $this->markUnavailable('projectedSubCounts');
        } else {
            $this->projectedSubCounts = $this->metricMap('projectedSubCounts', $projectedSubCounts);
        }
        if ($this->availability('englishLearnersCount') === DataAvailability::NotCollected) {
            $this->markUnavailable('englishLearnersCount');
        } else {
            $this->englishLearnersCount = $englishLearnersCount;
        }
        if ($this->availability('englishLearnersSubCounts') === DataAvailability::NotCollected) {
            $this->markUnavailable('englishLearnersSubCounts');
        } else {
            $this->englishLearnersSubCounts = $this->metricMap('englishLearnersSubCounts', $englishLearnersSubCounts);
        }
    }
}
