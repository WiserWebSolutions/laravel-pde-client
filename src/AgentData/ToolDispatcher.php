<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use Throwable;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;

final class ToolDispatcher
{
    public function __construct(
        private readonly ToolDefinitions $definitions,
        private readonly InputValidator $validator,
        private readonly DatasetCatalog $catalog,
        private readonly QueryService $queries,
        private readonly DistrictDirectory $districts,
        private readonly AnalysisService $analysis,
        private readonly Paginator $paginator,
    ) {}

    public function execute(string $operation, array $arguments = []): array
    {
        try {
            $definition = $this->definitions->get($operation);
            $arguments = $this->validator->validate($arguments, $definition['properties']);
            if ($definition['dataset'] !== null) {
                $dataset = $definition['dataset'];
                $result = $this->queries->fetch($dataset, $arguments['aun'], $arguments['year'] ?? null,
                    $this->queries->filters($dataset, $arguments), $arguments['fields'] ?? null);

                return $this->paginator->page($result, $arguments);
            }

            return match ($operation) {
                'list_datasets' => Result::make($this->catalog->all()),
                'describe_dataset' => $this->queries->describe($arguments['dataset'], $arguments['year'] ?? null, $this->queries->filters($arguments['dataset'], $arguments)),
                'find_districts' => $this->paginator->page($this->districts->search($arguments['search'], $arguments['year'] ?? null), $arguments),
                'calculate_total' => $this->analysis->total($arguments),
                'budget_variance' => $this->analysis->variance($arguments),
                'trend' => $this->analysis->compare($arguments, true),
                'compare_districts' => $this->analysis->compare($arguments, false),
            };
        } catch (ToolException $exception) {
            return Result::error($exception->reason, $exception->getMessage());
        } catch (DataSetNotFoundException $exception) {
            if ($exception->matches === 0) {
                return Result::make(warnings: [['code' => 'not_reported', 'message' => 'The requested dataset or reporting period is not published.']], status: 'unavailable');
            }

            return $this->failure($exception);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    private function failure(Throwable $exception): array
    {
        report($exception);

        return Result::error('operational_error', 'PDE data could not be retrieved or parsed. Check application logs and retry; this is not missing data.');
    }
}
