<?php

namespace WiserWebSolutions\PDEClient\Enrollment;

use ArrayIterator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Enums\EnrollmentDataset;
use WiserWebSolutions\PDEClient\Enums\Grade;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\YearTable;
use WiserWebSolutions\PDEClient\FinancialDataElements\AdmQuery;
use WiserWebSolutions\PDEClient\FiscalYear;

/**
 * Fluent query over one district's PDE enrollment data - general enrollment
 * by default, optionally alongside or exclusively English learner counts;
 * actual data only by default, optionally alongside or exclusively
 * projections; broken down per grade (PK, K, 1-12 - see Grade::normalize()).
 * This is the hub of the "enrollments" category: economicallyDisadvantaged()
 * and averageDailyMembership()/adm() branch into the category's other
 * datasets, carrying over whatever district()/year() is already set.
 *
 *     PDE::district()->enrollments();                          // most recent actual year, every grade
 *     PDE::district()->year('2023-2024')->enrollments();        // one year
 *     PDE::district()->enrollments()->allYears();                // every actual year available
 *     PDE::district()->enrollments()->withProjections();         // actual AND projected data, side by side
 *     PDE::district()->enrollments()->onlyProjections();         // projected data instead of actual
 *     PDE::district()->enrollments()->withoutProjections();      // actual only (the default - undoes with/onlyProjections())
 *     PDE::district()->enrollments()->withEnglishLearners();    // general enrollment AND EL counts, side by side
 *     PDE::district()->enrollments()->onlyEnglishLearners();     // EL counts instead of general enrollment
 *     PDE::district()->enrollments()->withAllDatasets();         // every dataset this query knows how to blend in
 *     PDE::district()->enrollments()->economicallyDisadvantaged()->get();
 *     PDE::district()->enrollments()->averageDailyMembership()->get();
 *     PDE::district()->enrollments()->withEconomicallyDisadvantaged()->get();  // econ. disadvantaged nested into the summary below
 *
 * Filters accumulate until a terminal call (get/first/sole/total), the same
 * shape as FinancialQuery. Omitting year() returns just the most recent
 * year available for whatever population(s) are selected - projections and
 * EL each publish a different year range than general enrollment, so "most
 * recent" depends on what's actually been chosen, and a query blending
 * multiple datasets takes the union of their year ranges. Actual data only
 * is the default for every year selection (bare, allYears(), or an explicit
 * year()) - PDE's projections workbook reaches years ahead of the last
 * actual year, and this query should surface real data by default rather
 * than a projection; call withProjections()/onlyProjections() to reach
 * projected years. Call allYears()/years()/year('all') for every year
 * available instead of just the most recent.
 *
 * get()/first()/sole() don't return per-grade EnrollmentRecords directly -
 * they fold every selected dataset (general enrollment, English learners,
 * projections, and - with withEconomicallyDisadvantaged() - economically
 * disadvantaged) into one EnrollmentYearSummary per school year, with every
 * underlying per-grade record nested in `grades` for drill-down. get()
 * returns that single EnrollmentYearSummary directly for a query matching
 * exactly one year, or a Collection<int, EnrollmentYearSummary> for a
 * multi-year query (allYears(), or anything else matching more than one
 * year); first()/sole() always return a single EnrollmentYearSummary
 * (sole() throwing if more than one year matched). See EnrollmentYearSummary
 * for its shape, and total() for the flat, un-summarized grand total.
 *
 * @implements IteratorAggregate<int, EnrollmentYearSummary>
 */
class EnrollmentQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    /** @var list<EnrollmentDataset> non-empty; which population(s) to include in the result */
    private array $datasets = [EnrollmentDataset::Enrollment];

    /** @var 'actual'|'both'|'projections' default 'actual' - which projection status(es) the result includes */
    private string $projectionsMode = 'actual';

    /** @var list<string>|null null = all grades */
    private ?array $grades = null;

    /** whether each year's EnrollmentYearSummary should attach its EconomicallyDisadvantagedRecord */
    private bool $withEconomicallyDisadvantaged = false;

    public function __construct(
        private readonly EnrollmentDataRepository $repository,
        private readonly Container $container,
    ) {
    }

    /** Economically disadvantaged (low-income) counts - a sibling dataset in the enrollments category. */
    public function economicallyDisadvantaged(): EconomicallyDisadvantagedQuery
    {
        return $this->seedSibling($this->container->make(EconomicallyDisadvantagedQuery::class));
    }

    /** Average Daily Membership - a sibling dataset in the enrollments category. */
    public function averageDailyMembership(): AdmQuery
    {
        return $this->seedSibling($this->container->make(AdmQuery::class));
    }

    /** Alias for averageDailyMembership(). */
    public function adm(): AdmQuery
    {
        return $this->averageDailyMembership();
    }

    /** Includes projection rows alongside actual data (the default is actual only). */
    public function withProjections(): static
    {
        $this->projectionsMode = 'both';

        return $this;
    }

    /** Actual data only, excluding projections - this is the default; mainly useful to undo withProjections()/onlyProjections(). */
    public function withoutProjections(): static
    {
        $this->projectionsMode = 'actual';

        return $this;
    }

    /** Restricts the result to projection rows only, instead of actual data. */
    public function onlyProjections(): static
    {
        $this->projectionsMode = 'projections';

        return $this;
    }

    /** Adds English learner counts alongside whatever dataset(s) are already selected. */
    public function withEnglishLearners(): static
    {
        if (! in_array(EnrollmentDataset::EnglishLearners, $this->datasets, true)) {
            $this->datasets[] = EnrollmentDataset::EnglishLearners;
        }

        return $this;
    }

    /** Restricts the result to English learner counts only, instead of general enrollment. */
    public function onlyEnglishLearners(): static
    {
        $this->datasets = [EnrollmentDataset::EnglishLearners];

        return $this;
    }

    /**
     * Includes every dataset this query knows how to blend into one
     * EnrollmentYearSummary per year - every EnrollmentDataset case, plus
     * economically disadvantaged (a sibling dataset outside that enum,
     * since it has no grade breakdown to live in EnrollmentRecord), same as
     * calling withEconomicallyDisadvantaged() explicitly.
     */
    public function withAllDatasets(): static
    {
        $this->datasets = EnrollmentDataset::cases();
        $this->withEconomicallyDisadvantaged = true;

        return $this;
    }

    /**
     * Tells get()/first()/sole() to also attach each year's
     * EconomicallyDisadvantagedRecord to that year's EnrollmentYearSummary.
     * No effect on total(), which stays a flat sum across every selected
     * dataset - economically disadvantaged data has no grade breakdown to
     * fold into that.
     */
    public function withEconomicallyDisadvantaged(): static
    {
        $this->withEconomicallyDisadvantaged = true;

        return $this;
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
     * One EnrollmentYearSummary per school year in the result, merging every
     * selected dataset (general enrollment, English learners, projections,
     * and - with withEconomicallyDisadvantaged() - economically
     * disadvantaged) into that year's totals, with every underlying
     * per-grade EnrollmentRecord nested in `grades` for drill-down. A query
     * that resolves to exactly one school year (an explicit year(), or the
     * most-recent-year default) returns that single EnrollmentYearSummary
     * directly rather than wrapping it in a Collection; a multi-year query
     * (allYears(), or any other case matching more than one year) returns a
     * Collection<int, EnrollmentYearSummary> instead.
     *
     * @return EnrollmentYearSummary|Collection<int, EnrollmentYearSummary>
     */
    public function get(): EnrollmentYearSummary|Collection
    {
        $summaries = $this->summaries();

        return $summaries->count() === 1 ? $summaries->first() : $summaries;
    }

    public function first(): ?EnrollmentYearSummary
    {
        return $this->summaries()->first();
    }

    /**
     * Exactly one year's summary or a loud failure - for "this district's
     * 2024-25 enrollment", not "whichever year happened to sort first".
     */
    public function sole(): EnrollmentYearSummary
    {
        $summaries = $this->summaries();

        return match (true) {
            $summaries->isEmpty() => throw DataSetNotFoundException::noneMatched($this->filterDescription()),
            $summaries->count() > 1 => throw DataSetNotFoundException::multipleMatched($this->filterDescription(), $summaries->count()),
            default => $summaries->first(),
        };
    }

    /** Sum of every selected dataset's count across the matched records - every dataset/projection status/grade, undifferentiated. */
    public function total(): float
    {
        return $this->records()->sum(
            fn (EnrollmentRecord $record) => ($record->count ?? 0.0) + ($record->projectedCount ?? 0.0) + ($record->englishLearnersCount ?? 0.0)
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->summaries()->all());
    }

    /**
     * One EnrollmentYearSummary per school year matched by records(),
     * folding every selected dataset into that year's named totals. Always
     * a Collection, even for a single year - get() is what collapses a
     * single-year result down to the bare EnrollmentYearSummary.
     *
     * @return Collection<int, EnrollmentYearSummary>
     */
    private function summaries(): Collection
    {
        return $this->records()
            ->groupBy(fn (EnrollmentRecord $record) => $record->schoolYear)
            ->map(function (Collection $records, string $schoolYear) {
                /** @var EnrollmentRecord $first */
                $first = $records->first();

                $enrollmentTotal = null;
                $projectedEnrollmentTotal = null;
                $englishLearnersTotal = null;

                foreach ($records as $record) {
                    if ($record->count !== null) {
                        $enrollmentTotal = ($enrollmentTotal ?? 0.0) + $record->count;
                    }

                    if ($record->projectedCount !== null) {
                        $projectedEnrollmentTotal = ($projectedEnrollmentTotal ?? 0.0) + $record->projectedCount;
                    }

                    if ($record->englishLearnersCount !== null) {
                        $englishLearnersTotal = ($englishLearnersTotal ?? 0.0) + $record->englishLearnersCount;
                    }
                }

                $economicallyDisadvantaged = $this->economicallyDisadvantagedFor($first->aun, $schoolYear);

                return new EnrollmentYearSummary(
                    aun: $first->aun,
                    districtName: $first->districtName,
                    county: $first->county,
                    leaType: $first->leaType,
                    schoolYear: $schoolYear,
                    enrollmentTotal: $enrollmentTotal,
                    projectedEnrollmentTotal: $projectedEnrollmentTotal,
                    englishLearnersTotal: $englishLearnersTotal,
                    economicallyDisadvantagedTotal: $economicallyDisadvantaged?->economicallyDisadvantagedCount,
                    economicallyDisadvantaged: $economicallyDisadvantaged,
                    grades: $records->values(),
                );
            })
            ->values()
            ->sort(fn (EnrollmentYearSummary $a, EnrollmentYearSummary $b) => $a->schoolYear <=> $b->schoolYear)
            ->values();
    }

    /**
     * Only ever populated when withEconomicallyDisadvantaged() was called,
     * and even then only for years PDE's economically disadvantaged
     * workbook actually publishes (2016-17 onward) - that dataset has no
     * grade breakdown, no English-learner-specific figures, and no
     * projections, so it's looked up once per year regardless of which
     * dataset(s)/projection status the year's records came from.
     */
    private function economicallyDisadvantagedFor(string $aun, string $schoolYear): ?EconomicallyDisadvantagedRecord
    {
        if (! $this->withEconomicallyDisadvantaged) {
            return null;
        }

        try {
            $table = $this->repository->economicallyDisadvantagedTable(FiscalYear::parse($schoolYear));
        } catch (PDEClientException) {
            return null;
        }

        $district = $table->districts[$aun] ?? null;
        $row = ($table->rows[$aun] ?? [])[0] ?? null;

        if ($district === null || $row === null) {
            return null;
        }

        return new EconomicallyDisadvantagedRecord(
            aun: $aun,
            districtName: $district['name'] ?? null,
            leaType: $district['lea_type'] ?? null,
            county: $district['county'] ?? null,
            schoolYear: $schoolYear,
            economicallyDisadvantagedCount: $row['economically_disadvantaged'],
            enrollment: $row['enrollment'],
            percentEconomicallyDisadvantaged: $row['percent'],
        );
    }

    /**
     * The flat, per-grade records every EnrollmentYearSummary is built
     * from - one row per (school year, grade) this query matched, merging
     * every selected dataset (general enrollment, projections, English
     * learners) into that one row rather than emitting a separate record
     * per dataset for the same grade, prior to any year-level summarizing.
     *
     * @return Collection<int, EnrollmentRecord>
     */
    private function records(): Collection
    {
        $aun = $this->resolveAun();
        $years = $this->resolveYears();

        $records = collect();
        $anyTableChecked = false;
        $districtSeen = false;

        foreach ($years as $year) {
            $byGrade = [];

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

                    $byGrade[$grade] ??= [
                        'district' => $district,
                        'count' => null,
                        'subCounts' => [],
                        'projectedCount' => null,
                        'projectedSubCounts' => [],
                        'englishLearnersCount' => null,
                        'englishLearnersSubCounts' => [],
                    ];

                    [$countKey, $subCountsKey] = match (true) {
                        $dataset === EnrollmentDataset::EnglishLearners => ['englishLearnersCount', 'englishLearnersSubCounts'],
                        $isProjection => ['projectedCount', 'projectedSubCounts'],
                        default => ['count', 'subCounts'],
                    };

                    $byGrade[$grade][$countKey] = ($byGrade[$grade][$countKey] ?? 0.0) + $data['count'];
                    $byGrade[$grade][$subCountsKey] = [...$byGrade[$grade][$subCountsKey], ...$data['subCounts']];
                }
            }

            foreach ($byGrade as $grade => $data) {
                $records->push(new EnrollmentRecord(
                    aun: $aun,
                    districtName: $data['district']['name'] ?? null,
                    county: $data['district']['county'] ?? null,
                    leaType: $data['district']['lea_type'] ?? null,
                    schoolYear: $year->long(),
                    grade: $grade,
                    count: $data['count'],
                    subCounts: $data['subCounts'],
                    projectedCount: $data['projectedCount'],
                    projectedSubCounts: $data['projectedSubCounts'],
                    englishLearnersCount: $data['englishLearnersCount'],
                    englishLearnersSubCounts: $data['englishLearnersSubCounts'],
                ));
            }
        }

        // Only a genuine "AUN not found in anything we looked at" is an
        // error. A query that structurally has nothing to look at at all
        // (e.g. onlyEnglishLearners()->onlyProjections() - no EL projections exist)
        // is a valid, merely empty, result rather than a failed lookup.
        if ($anyTableChecked && ! $districtSeen) {
            throw DataSetNotFoundException::noneMatched("district AUN [{$aun}] in the requested enrollment data");
        }

        return $records
            ->sort(fn (EnrollmentRecord $a, EnrollmentRecord $b) => [$a->schoolYear, Grade::sortIndex($a->grade)]
                <=> [$b->schoolYear, Grade::sortIndex($b->grade)])
            ->values();
    }

    /**
     * @return list<FiscalYear>
     */
    private function resolveYears(): array
    {
        $available = array_reduce(
            $this->datasets,
            fn (array $years, EnrollmentDataset $dataset) => $this->unionYears($years, $this->availableYearsFor($dataset)),
            [],
        );

        $years = $this->selectYears($available);

        if ($years === []) {
            throw DataSetNotFoundException::noneMatched('any fiscal year published for the requested enrollment data');
        }

        return $years;
    }

    /**
     * @return list<FiscalYear> newest first
     */
    private function availableYearsFor(EnrollmentDataset $dataset): array
    {
        return match (true) {
            $dataset === EnrollmentDataset::EnglishLearners => $this->repository->availableElYears(),
            $this->projectionsMode === 'projections' => $this->repository->availableProjectionYears(),
            // Default mode is 'actual' - "most recent"/"every year" should
            // mean actual data, not a future projection (PDE publishes
            // projections years ahead of the last actual year), unless
            // withProjections()/onlyProjections() was explicitly called.
            $this->projectionsMode === 'actual' => $this->repository->availablePublicYears(),
            default => $this->unionYears(
                $this->repository->availablePublicYears(),
                $this->repository->availableProjectionYears(),
            ),
        };
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
        $tables = [];

        foreach ($this->datasets as $dataset) {
            if ($dataset === EnrollmentDataset::EnglishLearners) {
                // No EL projections exist at all; asking for projections-only
                // English learner data is a valid query that simply has no data.
                if ($this->projectionsMode === 'projections') {
                    continue;
                }

                $tables[] = $this->tryTable(fn () => $this->repository->elTable($year), false, EnrollmentDataset::EnglishLearners);

                continue;
            }

            if ($this->projectionsMode !== 'projections') {
                $tables[] = $this->tryTable(fn () => $this->repository->publicTable($year), false, EnrollmentDataset::Enrollment);
            }

            if ($this->projectionsMode !== 'actual') {
                $tables[] = $this->tryTable(fn () => $this->repository->projectionTable($year), true, EnrollmentDataset::Enrollment);
            }
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
     * @return array{0: EnrollmentDataset, 1: bool, 2: YearTable}|null
     */
    private function tryTable(callable $load, bool $isProjection, EnrollmentDataset $dataset): ?array
    {
        try {
            return [$dataset, $isProjection, $load()];
        } catch (PDEClientException) {
            return null;
        }
    }

    private function filterDescription(): string
    {
        $parts = array_filter([
            "district [{$this->aun}]",
            $this->year !== null ? "year [{$this->year->short()}]" : null,
            'dataset(s) ['.implode(', ', array_map(fn (EnrollmentDataset $dataset) => $dataset->value, $this->datasets)).']',
            match ($this->projectionsMode) {
                'actual' => 'actuals only',
                'projections' => 'projections only',
                default => null,
            },
            $this->grades !== null ? 'grade(s) ['.implode(', ', $this->grades).']' : null,
        ]);

        return implode(', ', $parts);
    }
}
