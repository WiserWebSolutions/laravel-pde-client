<?php

namespace WiserWebSolutions\PDEClient\Graduation;

use Illuminate\Support\Str;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Support\RowTable;

final class DropoutAggregator
{
    public function aggregate(RowTable $input): RowTable
    {
        $rows = $cells = [];
        foreach ($input->rows as $aun => $schoolRows) {
            if (count($schoolRows) === 1) {
                $rows[$aun] = $schoolRows;
                $cells[$aun] = $input->cells[$aun] ?? [];

                continue;
            }
            $totals = [];
            foreach (['enrollment', 'male_dropouts', 'female_dropouts', 'dropouts'] as $field) {
                $total = 0.0;
                $missing = null;
                foreach ($schoolRows as $index => $row) {
                    $state = $input->availabilityFor((string) $aun, $index)->state(Str::camel($field), $row[$field]);
                    if ($state !== DataAvailability::Available) {
                        if ($missing === null || $state->missingPriority() > $missing->missingPriority()) {
                            $missing = $state;
                        }
                    } else {
                        $total += $row[$field];
                    }
                }
                $totals[$field] = $missing === null ? $total : null;
                if ($missing !== null) {
                    $cells[$aun][0][$field] = $missing->value;
                }
            }
            $totals['rate'] = $totals['dropouts'] !== null && $totals['enrollment'] !== null && $totals['enrollment'] > 0
                ? round($totals['dropouts'] / $totals['enrollment'], 12) : null;
            if ($totals['rate'] === null) {
                $cells[$aun][0]['rate'] = $cells[$aun][0]['dropouts'] ?? $cells[$aun][0]['enrollment'] ?? DataAvailability::NotApplicable->value;
            }
            $rows[$aun] = [$totals];
        }

        return new RowTable($input->districts, $rows, $input->schema, $cells, $input->source, $input->sources, $input->provenance);
    }
}
