<?php

namespace WiserWebSolutions\PDEClient\Tests\Personnel;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Enums\PersonnelCategory;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\FiscalYear;
use WiserWebSolutions\PDEClient\Personnel\PersonnelDataRepository;
use WiserWebSolutions\PDEClient\Personnel\PersonnelQuery;
use WiserWebSolutions\PDEClient\Personnel\PersonnelRecord;
use WiserWebSolutions\PDEClient\Support\RowTable;
use WiserWebSolutions\PDEClient\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PersonnelQueryTest extends TestCase
{
    private const AUN = '124157203';

    public function test_get_returns_every_category_for_the_most_recent_year(): void
    {
        $records = $this->makeQuery($this->fakeRepository())->district(self::AUN)->get();

        $this->assertCount(2, $records);
        $this->assertContainsOnlyInstancesOf(PersonnelRecord::class, $records);
    }

    public function test_category_filters_by_enum_or_string(): void
    {
        $byEnum = $this->makeQuery($this->fakeRepository())->district(self::AUN)->category(PersonnelCategory::Administrator)->sole();
        $byString = $this->makeQuery($this->fakeRepository())->district(self::AUN)->category('administrator')->sole();

        $this->assertSame(PersonnelCategory::Administrator, $byEnum->category);
        $this->assertSame(PersonnelCategory::Administrator, $byString->category);
    }

    public function test_classroom_teachers_shortcut(): void
    {
        $record = $this->makeQuery($this->fakeRepository())->district(self::AUN)->classroomTeachers()->sole();

        $this->assertSame(PersonnelCategory::ClassroomTeacher, $record->category);
        $this->assertSame(50.5, $record->averageSalary);
    }

    public function test_administrators_shortcut(): void
    {
        $record = $this->makeQuery($this->fakeRepository())->district(self::AUN)->administrators()->sole();

        $this->assertSame(PersonnelCategory::Administrator, $record->category);
    }

    public function test_unmatched_district_throws(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeQuery($this->fakeRepository(withDistrict: '999999999'))->district(self::AUN)->get();
    }

    public function test_sole_throws_when_more_than_one_record_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeQuery($this->fakeRepository())->district(self::AUN)->sole();
    }

    private function makeQuery(PersonnelDataRepository $repository): PersonnelQuery
    {
        return new PersonnelQuery($repository);
    }

    private function fakeRepository(string $withDistrict = self::AUN): PersonnelDataRepository
    {
        $repository = $this->createMock(PersonnelDataRepository::class);

        $district = [$withDistrict => ['name' => 'Phoenixville Area SD', 'lea_type' => 'SD', 'county' => 'Chester']];

        $repository->method('availableYears')->willReturn([FiscalYear::parse('2024-2025')]);

        $repository->method('table')->willReturnCallback(function (FiscalYear $year) use ($district, $withDistrict) {
            if ($year->long() !== '2024-2025') {
                throw DataSetNotFoundException::noneMatched("year [{$year->long()}]");
            }

            return new RowTable($district, [
                $withDistrict => [
                    [
                        'category' => 'administrator', 'count' => 5.0, 'female' => 3.0, 'male' => 2.0,
                        'salary' => 90000.0, 'service' => 12.0, 'lea_years' => 8.0, 'education' => 6.0,
                    ],
                    [
                        'category' => 'classroom_teacher', 'count' => 100.0, 'female' => 70.0, 'male' => 30.0,
                        'salary' => 50.5, 'service' => 9.0, 'lea_years' => 7.0, 'education' => 5.0,
                    ],
                ],
            ]);
        });

        return $repository;
    }
}
