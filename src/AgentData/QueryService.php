<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use Illuminate\Support\Str;
use WiserWebSolutions\PDEClient\Enums\DataAvailability;
use WiserWebSolutions\PDEClient\Exceptions\DataNotCollectedException;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Support\RowTable;

final class QueryService
{
    public function __construct(
        private readonly DatasetCatalog $catalog,
        private readonly DatasetAccess $access,
        private readonly RecordSerializer $serializer,
    ) {}

    public function filters(string $dataset, array $arguments): array
    {
        $allFilters = ['category', 'accounts', 'grades', 'subjects', 'groups', 'cohort_years', 'fund_types', 'phases', 'personnel_categories', 'county', 'notes'];
        $filters = array_intersect_key($arguments, array_flip($allFilters));
        $unsupported = array_diff(array_keys($filters), $this->catalog->get($dataset)['filters']);
        if ($unsupported !== []) {
            throw new ToolException('invalid_arguments', 'Unsupported filters for '.$dataset.': '.implode(', ', $unsupported));
        }
        if ($dataset === 'cohort-graduation') {
            $filters['cohort_years'] ??= 4;
        }

        return $filters;
    }

    public function fetch(string $dataset, string $aun, ?string $year, array $filters = [], ?array $fields = null): array
    {
        $this->catalog->get($dataset);
        $resolved = $year === null ? ($this->access->years($dataset, $filters)[0] ?? null) : FiscalYear::parse($year);
        $context = ['dataset' => $dataset, 'aun' => $aun, 'year' => $resolved?->long(), 'filters' => $filters];
        if ($resolved === null) {
            return Result::make(context: $context, warnings: [['code' => 'not_reported', 'message' => 'No published year was discovered.']], status: 'unavailable');
        }
        $sources = [];
        try {
            foreach ($this->access->tables($dataset, $resolved, $filters) as $table) {
                $sources = [...$sources, ...SourceReference::forTable($table)];
            }
            $query = $this->access->query($dataset, $aun, $resolved, $filters);
            $rows = [];
            foreach ($query->records() as $record) {
                if (! $this->matches($record, $filters)) {
                    continue;
                }
                $rows[] = $this->serializer->serialize($record, $sources, $fields);
            }
        } catch (DataNotCollectedException $exception) {
            return Result::make(context: $context, sources: $sources, warnings: [['code' => 'not_collected', 'field' => $exception->field, 'message' => 'The recognized report does not publish this selection.']], status: 'unavailable');
        } catch (DataSetNotFoundException $exception) {
            if ($exception->matches > 1) {
                throw $exception;
            }
            $rows = [];
        }
        // Query and record order is independent of dictionary order in source workbooks.
        usort($rows, fn ($a, $b) => strcmp(json_encode($a['values'], JSON_THROW_ON_ERROR), json_encode($b['values'], JSON_THROW_ON_ERROR)));
        $warnings = $rows === [] ? [['code' => 'not_reported', 'message' => 'No matching published rows.']] : [];

        return Result::make($rows, $context, $this->catalog->units($dataset), $sources, $warnings, $rows === [] ? 'unavailable' : 'ok');
    }

    public function describe(string $dataset, ?string $year, array $filters): array
    {
        $entry = $this->catalog->get($dataset);
        $entry['available_years'] = $this->catalog->availableYears($dataset, $filters);
        $sources = $schemas = [];
        if ($year !== null) {
            foreach ($this->access->tables($dataset, FiscalYear::parse($year), $filters) as $table) {
                $sources = [...$sources, ...SourceReference::forTable($table)];
                $schemas[] = $table instanceof RowTable
                    ? $table->schema
                    : ['published_codes' => array_keys($table->accountNames), 'groups_complete' => false];
            }
        }
        $entry['observed_schema'] = $schemas;

        return Result::make([$entry], ['dataset' => $dataset, 'year' => $year, 'filters' => $filters], sources: $sources);
    }

    private function matches(object $record, array $filters): bool
    {
        foreach (['county', 'notes'] as $field) {
            if (isset($filters[$field])) {
                if ($record->availability($field) !== DataAvailability::Available
                    || Str::lower(trim($record->{$field})) !== Str::lower(trim($filters[$field]))) {
                    return false;
                }
            }
        }

        return true;
    }
}
