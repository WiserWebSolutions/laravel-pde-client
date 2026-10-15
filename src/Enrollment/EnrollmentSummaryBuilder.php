<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use Illuminate\Support\Collection;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\AvailabilityMetadata;
use WiserWebSolutions\PDEClient\Support\DataProvenance;
use WiserWebSolutions\PDEClient\Support\MetricAggregation;

final class EnrollmentSummaryBuilder
{
    public function build(Collection $records, ?EconomicallyDisadvantagedRecord $disadvantaged, bool $disadvantagedRequested): EnrollmentYearSummary
    {
        $first = $records->first();
        [$enrollment, $enrollmentState] = MetricAggregation::sum($records, 'count', 'subCounts');
        [$projections, $projectionState] = MetricAggregation::sum($records, 'projectedCount', 'projectedSubCounts');
        [$learners, $learnerState] = MetricAggregation::sum($records, 'englishLearnersCount', 'englishLearnersSubCounts');
        [$lowIncome, $lowIncomeState] = $disadvantaged === null
            ? [null, $disadvantagedRequested ? DataAvailability::NotReported : DataAvailability::NotRequested]
            : MetricAggregation::sum([$disadvantaged], 'economicallyDisadvantagedCount');

        $sources = $records->map(fn (EnrollmentRecord $record) => $record->availabilityContext()['source'] ?? null);
        if ($disadvantaged !== null) {
            $sources->push($disadvantaged->availabilityContext()['source'] ?? null);
        }

        $provenance = DataProvenance::forRecordField($records, 'count', 'enrollmentTotal')
            ->merge(DataProvenance::forRecordField($records, 'projectedCount', 'projectedEnrollmentTotal'))
            ->merge(DataProvenance::forRecordField($records, 'englishLearnersCount', 'englishLearnersTotal'))
            ->merge(DataProvenance::forRecordField($disadvantaged === null ? [] : [$disadvantaged],
                'economicallyDisadvantagedCount', 'economicallyDisadvantagedTotal'));

        return new EnrollmentYearSummary(
            aun: $first->aun, districtName: $first->districtName, county: $first->county,
            leaType: $first->leaType, schoolYear: $first->schoolYear,
            enrollmentTotal: $enrollment, projectedEnrollmentTotal: $projections,
            englishLearnersTotal: $learners, economicallyDisadvantagedTotal: $lowIncome,
            economicallyDisadvantaged: $disadvantaged, grades: $records->values(),
            availability: new AvailabilityMetadata([
                'enrollmentTotal' => $enrollmentState, 'projectedEnrollmentTotal' => $projectionState,
                'englishLearnersTotal' => $learnerState, 'economicallyDisadvantagedTotal' => $lowIncomeState,
                'economicallyDisadvantaged' => $disadvantagedRequested ? ($disadvantaged === null ? DataAvailability::NotReported : DataAvailability::Available) : DataAvailability::NotRequested,
            ], context: ['dataset' => 'enrollment', 'district' => $first->aun, 'year' => $first->schoolYear, 'source' => $sources->filter()->unique()->implode(', ')], provenance: $provenance),
        );
    }
}
