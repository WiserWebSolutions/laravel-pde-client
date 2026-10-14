<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use Illuminate\Support\Str;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentDataRepository;
use WiserWebSolutions\PDEClient\FiscalYear;

final class DistrictDirectory
{
    public function __construct(private readonly EnrollmentDataRepository $repository) {}

    public function search(string $search, ?string $year = null): array
    {
        $years = $year === null ? $this->repository->availablePublicYears() : [FiscalYear::parse($year)];
        usort($years, fn ($a, $b) => $b->startYear <=> $a->startYear);
        $resolved = $years[0] ?? null;
        if ($resolved === null) {
            return Result::make(status: 'unavailable', warnings: [['code' => 'not_reported', 'message' => 'No enrollment source is available for district discovery.']]);
        }
        $table = $this->repository->publicTable($resolved);
        $needle = $this->normalize($search);
        if ($needle === '') {
            throw new ToolException('invalid_arguments', 'Supply a district name or AUN.');
        }
        $matches = [];
        foreach ($table->districts as $aun => $district) {
            $name = $this->normalize($district['name'] ?? '');
            $exact = (string) $aun === $search || $name === $needle;
            if ($exact || str_contains($name, $needle)) {
                $matches[] = [
                    'aun' => (string) $aun, 'name' => $district['name'] ?? null,
                    'county' => $district['county'] ?? null, 'lea_type' => $district['lea_type'] ?? null,
                    'source_year' => $resolved->long(), 'match' => $exact ? 'exact' : 'partial',
                ];
            }
        }
        usort($matches, fn ($a, $b) => [$a['match'] !== 'exact', $a['name'], $a['aun']] <=> [$b['match'] !== 'exact', $b['name'], $b['aun']]);

        return Result::make($matches, ['year' => $resolved->long(), 'search' => $search, 'ambiguous' => count($matches) > 1],
            sources: SourceReference::forTable($table),
            warnings: count($matches) > 1 ? [['code' => 'ambiguous_district', 'message' => 'Select an AUN; district names are not unique.']] : [],
            status: $matches === [] ? 'unavailable' : 'ok');
    }

    private function normalize(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))));
    }
}
