<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use WiserWebSolutions\PDEClient\Enums\EnrollmentMetric;
use WiserWebSolutions\PDEClient\Enums\FinancialCategory;
use WiserWebSolutions\PDEClient\Enums\Grade;
use WiserWebSolutions\PDEClient\Enums\Measure;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\ChartOfAccounts;
use WiserWebSolutions\PDEClient\FiscalYear;

final class AnalysisService
{
    public function __construct(
        private readonly QueryService $queries,
        private readonly DatasetAccess $access,
        private readonly DatasetCatalog $catalog,
    ) {}

    public function total(array $arguments): array
    {
        $dataset = $arguments['dataset'];
        $filters = $this->queries->filters($dataset, $arguments);
        if (! in_array($dataset, ['gfb-budgets', 'afr-actuals', 'public-enrollment', 'enrollment-projections', 'english-learners'], true)) {
            throw new ToolException('unsupported_total', 'Totals are supported for financial accounts and disjoint enrollment grades.');
        }
        if (in_array($dataset, ['gfb-budgets', 'afr-actuals'], true) && ! isset($filters['category'])) {
            throw new ToolException('invalid_arguments', 'Financial totals require an explicit category.');
        }
        $points = $sources = [];
        foreach ($this->years($arguments['years']) as $year) {
            [$point, $references] = $this->totalPoint($dataset, $arguments['aun'], $year, $filters);
            $points[] = $point;
            $sources = [...$sources, ...$references];
        }

        return $this->result($points, $arguments, $sources);
    }

    public function variance(array $arguments): array
    {
        $points = $sources = [];
        $filters = array_intersect_key($arguments, array_flip(['category', 'accounts']));
        foreach ($this->years($arguments['years']) as $year) {
            [$budget, $budgetSources] = $this->totalPoint('gfb-budgets', $arguments['aun'], $year, $filters);
            [$actual, $actualSources] = $this->totalPoint('afr-actuals', $arguments['aun'], $year, $filters);
            $difference = $this->difference($actual, $budget, 'USD');
            $points[] = [
                'aun' => $arguments['aun'], 'year' => $year, 'unit' => 'USD',
                'budget' => $budget['value'], 'actual' => $actual['value'],
                'value' => $difference['difference'], 'metric' => 'actual_minus_budget',
                'availability' => $difference['availability'],
                'budget_availability' => $budget['availability'], 'actual_availability' => $actual['availability'],
                'percent_change' => $difference['percent_change'],
                'percent_change_availability' => $difference['percent_change_availability'],
            ];
            $sources = [...$sources, ...$budgetSources, ...$actualSources];
        }

        return $this->result($points, $arguments, $sources);
    }

    public function compare(array $arguments, bool $trend): array
    {
        $dataset = $arguments['dataset'];
        $filters = $this->queries->filters($dataset, $arguments);
        $metric = $arguments['metric'];
        $unit = $this->catalog->units($dataset)[$metric] ?? null;
        $field = $this->catalog->get($dataset)['fields'][$metric] ?? null;
        if ($unit === null || ($field['type'] ?? null) !== 'float') {
            throw new ToolException('invalid_metric', 'Choose a scalar numeric field from the dataset catalog.');
        }
        $years = $this->years($arguments['years']);
        if ($trend && count($years) < 2) {
            throw new ToolException('invalid_arguments', 'A trend requires at least two different years.');
        }
        $auns = $trend ? [$arguments['aun']] : $arguments['auns'];
        $points = $sources = [];
        $dimensions = null;
        $previous = null;
        foreach ($years as $year) {
            $baseline = null;
            foreach ($auns as $aun) {
                $result = $this->queries->fetch($dataset, $aun, $year, $filters);
                $sources = [...$sources, ...$result['sources']];
                if (count($result['data']) > 1) {
                    throw new ToolException('ambiguous_measurement', 'Filters must select exactly one record per district/year. Refine the account, grade, subject, group, category, or tax-rate location.');
                }
                $row = $result['data'][0] ?? null;
                $point = [
                    'aun' => $aun, 'year' => $year, 'metric' => $metric, 'unit' => $unit,
                    'value' => $row['values'][$metric] ?? null,
                    'availability' => $row['availability'][$metric] ?? ($result['warnings'][0]['code'] ?? 'not_reported'),
                ];
                if ($row !== null) {
                    $current = $this->dimensions($dataset, $row['values']);
                    if ($dimensions !== null && $current !== $dimensions) {
                        throw new ToolException('incompatible_measurements', 'Selected records have different measurement dimensions.');
                    }
                    $dimensions = $current;
                    $point['dimensions'] = $current;
                }
                $reference = $trend ? $previous : $baseline;
                if ($reference !== null) {
                    $point += $this->difference($point, $reference, $unit);
                    $point['baseline'] = ['aun' => $reference['aun'], 'year' => $reference['year']];
                }
                $points[] = $point;
                $baseline ??= $point;
                $previous = $point;
            }
        }

        return $this->result($points, $arguments, $sources);
    }

    private function totalPoint(string $dataset, string $aun, string $year, array $filters): array
    {
        $result = $this->queries->fetch($dataset, $aun, $year, $filters);
        $metric = match ($dataset) {
            'gfb-budgets' => 'budget', 'afr-actuals' => 'actual',
            'enrollment-projections' => 'projected_count', 'english-learners' => 'english_learners_count',
            default => 'count',
        };
        $point = ['aun' => $aun, 'year' => $year, 'metric' => $metric,
            'unit' => $this->catalog->units($dataset)[$metric], 'value' => null, 'availability' => 'not_reported'];
        if ($result['data'] === []) {
            $point['availability'] = $result['warnings'][0]['code'] ?? 'not_reported';

            return [$point, $result['sources']];
        }
        if (! $this->selectionIsComplete($filters, $result['data'])) {
            return [$point, $result['sources']];
        }
        if (count($result['data']) > 1 && ! in_array($dataset, ['gfb-budgets', 'afr-actuals'], true)) {
            foreach ($result['data'] as $row) {
                if (in_array(strtolower($row['values']['grade']), ['total', 'all', 'all grades'], true)) {
                    throw new ToolException('overlapping_measurements', 'A total-grade aggregate cannot be summed with individual grades.');
                }
            }
        }
        $query = $this->access->query($dataset, $aun, FiscalYear::parse($year), $filters);
        try {
            $value = match ($dataset) {
                'gfb-budgets', 'afr-actuals' => $query->total(
                    $dataset === 'gfb-budgets' ? Measure::Budget : Measure::Actual,
                    FinancialCategory::from($filters['category']),
                ),
                default => $query->total(match ($dataset) {
                    'enrollment-projections' => EnrollmentMetric::Projections,
                    'english-learners' => EnrollmentMetric::EnglishLearners,
                    default => EnrollmentMetric::Enrollment,
                }),
            };
            $point['value'] = $value;
            $point['availability'] = $value === null ? $this->missingReason($result['data'], $metric) : 'available';
        } catch (DataNotCollectedException) {
            $point['availability'] = 'not_collected';
        }

        return [$point, $result['sources']];
    }

    /** Missing explicitly requested components must not silently reduce a total. */
    private function selectionIsComplete(array $filters, array $rows): bool
    {
        $values = array_column($rows, 'values');
        if (isset($filters['grades'])) {
            $published = array_column($values, 'grade');
            foreach ($filters['grades'] as $grade) {
                if (! in_array(Grade::normalize($grade), $published, true)) {
                    return false;
                }
            }
        }
        if (isset($filters['accounts'])) {
            $published = array_column($values, 'account_code');
            $tree = app(ChartOfAccounts::class)->treeFor(FinancialCategory::from($filters['category']));
            foreach ($filters['accounts'] as $code) {
                while (! in_array($code, $published, true)) {
                    $code = $tree->parentOf($code);
                    if ($code === null) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function missingReason(array $rows, string $metric): string
    {
        $states = array_column(array_column($rows, 'availability'), $metric);
        foreach (['not_collected', 'suppressed', 'unknown', 'not_reported', 'not_applicable', 'not_requested'] as $reason) {
            if (in_array($reason, $states, true)) {
                return $reason;
            }
        }

        return 'unknown';
    }

    private function dimensions(string $dataset, array $values): array
    {
        $keys = match ($dataset) {
            'gfb-budgets', 'afr-actuals' => ['category', 'account_code'],
            'public-enrollment', 'enrollment-projections', 'english-learners' => ['grade'],
            'pssa', 'keystone' => ['exam', 'subject', 'grade', 'group'],
            'cohort-graduation' => ['cohort_years', 'group'],
            'indebtedness' => ['fund_type', 'phase'],
            'professional-personnel' => ['category'],
            'real-estate-tax-rates' => ['county', 'notes'],
            default => [],
        };
        $dimensions = array_intersect_key($values, array_flip($keys));
        foreach ($dimensions as &$value) {
            if (is_string($value)) {
                $value = strtolower(trim($value));
            }
        }

        return $dimensions;
    }

    private function difference(array $point, array $baseline, string $unit): array
    {
        $available = $point['value'] !== null && $baseline['value'] !== null;
        $rate = in_array($unit, ['percent', 'fraction'], true);
        $change = $available ? $point['value'] - $baseline['value'] : null;
        $reason = $point['value'] === null ? $point['availability'] : $baseline['availability'];

        return [
            'difference' => $change === null ? null : round($change * ($unit === 'fraction' ? 100 : 1), 10),
            'difference_unit' => $rate ? 'percentage_points' : $unit,
            'difference_availability' => $available ? 'available' : $reason,
            'availability' => $available ? 'available' : $reason,
            'percent_change' => $available && $baseline['value'] != 0 ? round($change / $baseline['value'] * 100, 10) : null,
            'percent_change_availability' => ! $available ? $reason : ($baseline['value'] == 0 ? 'zero_baseline' : 'available'),
        ];
    }

    private function years(array $years): array
    {
        $canonical = array_map(fn ($year) => FiscalYear::parse($year)->long(), $years);
        if (count($canonical) !== count(array_unique($canonical))) {
            throw new ToolException('invalid_arguments', 'Years must identify different reporting periods.');
        }
        sort($canonical);

        return $canonical;
    }

    private function result(array $points, array $arguments, array $sources): array
    {
        $incomplete = count(array_filter($points, fn ($point) => $point['value'] === null)) > 0;

        return Result::make($points, $arguments, ['value' => $points[0]['unit'] ?? null], $sources,
            $incomplete ? [['code' => 'incomplete_analysis', 'message' => 'Missing or suppressed inputs make some calculations unavailable.']] : [],
            $incomplete ? 'partial' : 'ok');
    }
}
