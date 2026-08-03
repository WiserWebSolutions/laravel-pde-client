<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use ArrayIterator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Fluent query over one district's PDE enrollment data - general enrollment
 * by default, or English learner counts; actual, projected, or both;
 * broken down per grade (PK, K, 1-12 - see Grade::normalize()).
 *
 *     PDE::district()->enrollments();                          // every year, every grade, actual + projected
 *     PDE::district()->year('2023-2024')->enrollments();        // one year
 *     PDE::district()->enrollments()->projections(false);       // actuals only
 *     PDE::district()->enrollments()->projections();            // projections only
 *     PDE::district()->enrollments()->englishLearners();        // EL counts instead of general enrollment
 *
 * Filters accumulate until a terminal call (get/first/sole/total), the same
 * shape as FinancialQuery. Omitting year() returns every year available for
 * whatever population is selected - projections and EL each publish a
 * different year range than general enrollment, so "available" depends on
 * what's actually been chosen.
 *
 * @implements IteratorAggregate<int, EnrollmentRecord>
 */
class EnrollmentQuery implements AcceptsQueryContext, IteratorAggregate
{
    private ?string $aun = null;

    private ?FiscalYear $year = null;

    private string $dataset = EnrollmentRecord::DATASET_ENROLLMENT;

    /** @var bool|null null = actual + projected, true = projected only, false = actual only */
    private ?bool $projectionsMode = null;

    /** @var list<string>|null null = all grades */
    private ?array $grades = null;

    public function __construct(private readonly EnrollmentDataRepository $repository)
    {
    }

    /**
     * Selects the LEA by its 9-digit AUN. Called with no argument (or never
     * called), the configured default district applies.
     */
    public function district(?string $aun = null): static
    {
        $aun ??= config('pde-client.default_district');

        if ($aun === null || trim((string) $aun) === '') {
            throw new PDEClientException(
                'No district given and no default configured - set pde-client.default_district (PDE_CLIENT_DEFAULT_AUN) or pass an AUN.'
            );
        }

        $this->aun = trim((string) $aun);

        return $this;
    }

    /**
     * Accepts '2024-25', '2024-2025', '2024 - 2025', or 2024. Without an
     * explicit year the query resolves to every year available for the
     * selected population.
     */
    public function year(string|int|FiscalYear $year): static
    {
        $this->year = FiscalYear::parse($year);

        return $this;
    }

    /**
     * $only = false excludes projection rows (actuals only); $only = true
     * (the default - i.e. bare ->projections()) restricts to projections
     * only. Never calling this includes both.
     */
    public function projections(bool $only = true): static
    {
        $this->projectionsMode = $only;

        return $this;
    }

    /** English learner counts instead of general enrollment. */
    public function englishLearners(): static
    {
        $this->dataset = EnrollmentRecord::DATASET_ENGLISH_LEARNERS;

        return $this;
    }

    /** Alias for englishLearners(). */
    public function english_learners(): static
    {
        return $this->englishLearners();
    }

    /**
     * Restrict to specific grade(s): 'PK', 'K', or '1'-'12' (raw sub-codes
     * like 'K5F' or '001' are also accepted and normalized).
     */
    public function grade(string ...$grades): static
    {
        $this->grades = array_values(array_map(
            fn (string $grade) => Grade::normalize(trim($grade)),
            $grades,
        ));

        return $this;
    }

    /**
     * @return Collection<int, EnrollmentRecord>
     */
    public function get(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->resolveYears();

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            foreach ($this->tablesFor($year) as [$dataset, $isProjection, $table]) {
                $anyTableChecked = true;

                if (isset($table->districts[$aun])) {
                    $districtSeen = true;
                }

                $raw = $table->amounts[$aun] ?? [];

                if ($raw === []) {
                    continue;
                }

                $district = $table->districts[$aun] ?? ['name' => null, 'county' => null, 'lea_type' => null];
                $grouped = [];

                foreach ($raw as $code => $value) {
                    $grade = Grade::normalize((string) $code);
                    $grouped[$grade]['count'] = ($grouped[$grade]['count'] ?? 0.0) + $value;
                    $grouped[$grade]['subCounts'][$code] = $value;
                }

                foreach ($grouped as $grade => $data) {
                    if ($this->grades !== null && ! in_array($grade, $this->grades, true)) {
                        continue;
                    }

                    $records->push(new EnrollmentRecord(
                        aun: $aun,
                        districtName: $district['name'] ?? null,
                        county: $district['county'] ?? null,
                        leaType: $district['lea_type'] ?? null,
                        schoolYear: $year->long(),
                        dataset: $dataset,
                        isProjection: $isProjection,
                        grade: $grade,
                        count: $data['count'],
                        subCounts: $data['subCounts'],
                    ));
                }
            }
        }

        // Only a genuine "AUN not found in anything we looked at" is an
        // error. A query that structurally has nothing to look at at all
        // (e.g. englishLearners()->projections() - no EL projections exist)
        // is a valid, merely empty, result rather than a failed lookup.
        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested enrollment data");
        }

        return $records
            ->sort(fn (EnrollmentRecord $a, EnrollmentRecord $b) => [$a->schoolYear, $a->isProjection ? 1 : 0, Grade::sortIndex($a->grade)]
                <=> [$b->schoolYear, $b->isProjection ? 1 : 0, Grade::sortIndex($b->grade)])
            ->values();
    }

    public function first(): ?EnrollmentRecord
    {
        return $this->get()->first();
    }

    /**
     * Exactly one record or a loud failure - for "the K count for 2024-25",
     * not "whichever record happened to sort first".
     */
    public function sole(): EnrollmentRecord
    {
        $records = $this->get();

        return match (true) {
            $records->isEmpty() => throw DataSetNotFoundException::noneMatched($this->filterDescription()),
            $records->count() > 1 => throw DataSetNotFoundException::multipleMatched($this->filterDescription(), $records->count()),
            default => $records->first(),
        };
    }

    /** Sum of count() across the matched records. */
    public function total(): float
    {
        return $this->get()->sum(fn (EnrollmentRecord $record) => $record->count);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->get()->all());
    }

    private function resolveAun(): string
    {
        if ($this->aun === null) {
            $this->district();
        }

        return $this->aun;
    }

    /**
     * @return list<FiscalYear>
     */
    private function resolveYears(): array
    {
        if ($this->year !== null) {
            return [$this->year];
        }

        $years = match (true) {
            $this->dataset === EnrollmentRecord::DATASET_ENGLISH_LEARNERS => $this->repository->availableElYears(),
            $this->projectionsMode === true => $this->repository->availableProjectionYears(),
            $this->projectionsMode === false => $this->repository->availablePublicYears(),
            default => $this->unionYears(
                $this->repository->availablePublicYears(),
                $this->repository->availableProjectionYears(),
            ),
        };

        if ($years === []) {
            throw DataSetNotFoundException::noneMatched('any fiscal year published for the requested enrollment data');
        }

        return $years;
    }

    /**
     * @param  list<FiscalYear>  $a
     * @param  list<FiscalYear>  $b
     * @return list<FiscalYear> newest first
     */
    private function unionYears(array $a, array $b): array
    {
        $byStart = [];

        foreach ([...$a, ...$b] as $year) {
            $byStart[$year->startYear] = $year;
        }

        krsort($byStart);

        return array_values($byStart);
    }

    /**
     * @return list<array{0: string, 1: bool, 2: YearTable}> tuples of [dataset, isProjection, table]
     */
    private function tablesFor(FiscalYear $year): array
    {
        if ($this->dataset === EnrollmentRecord::DATASET_ENGLISH_LEARNERS) {
            // No EL projections exist at all; asking for projections-only
            // English learner data is a valid query that simply has no data.
            if ($this->projectionsMode === true) {
                return [];
            }

            return [[EnrollmentRecord::DATASET_ENGLISH_LEARNERS, false, $this->repository->elTable($year)]];
        }

        $tables = [];

        if ($this->projectionsMode !== true) {
            $tables[] = $this->tryTable(fn () => $this->repository->publicTable($year), false);
        }

        if ($this->projectionsMode !== false) {
            $tables[] = $this->tryTable(fn () => $this->repository->projectionTable($year), true);
        }

        return array_values(array_filter($tables));
    }

    /**
     * A requested year might simply not exist for one side of an
     * actual+projected pair (e.g. a future year has no public-enrollment
     * file yet; an old year predates the projections workbook), or - for
     * enrollment specifically - the workbook might genuinely be unparseable
     * (2004-05 through 2006-07 have no AUN column at all; see
     * PublicEnrollmentParser). Neither is an error for a query spanning
     * multiple years, just nothing to add for that one.
     *
     * @return array{0: string, 1: bool, 2: YearTable}|null
     */
    private function tryTable(callable $load, bool $isProjection): ?array
    {
        try {
            return [EnrollmentRecord::DATASET_ENROLLMENT, $isProjection, $load()];
        } catch (PDEClientException) {
            return null;
        }
    }

    private function filterDescription(): string
    {
        $parts = array_filter([
            "district [{$this->aun}]",
            $this->year !== null ? "year [{$this->year->short()}]" : null,
            "dataset [{$this->dataset}]",
            $this->projectionsMode !== null ? ($this->projectionsMode ? 'projections only' : 'actuals only') : null,
            $this->grades !== null ? 'grade(s) ['.implode(', ', $this->grades).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
