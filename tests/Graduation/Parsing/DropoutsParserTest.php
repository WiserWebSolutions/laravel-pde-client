<?php

namespace WiserWebSolutions\PDEClient\Tests\Graduation\Parsing;

use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;
use WiserWebSolutions\PDEClient\FinancialData\Parsing\SpreadsheetReader;
use WiserWebSolutions\PDEClient\Graduation\Parsing\DropoutsParser;
use WiserWebSolutions\PDEClient\Tests\Support\BuildsFixtureWorkbook;
use WiserWebSolutions\PDEClient\Tests\TestCase;

class DropoutsParserTest extends TestCase
{
    use BuildsFixtureWorkbook;

    public function test_parses_an_already_per_lea_sheet_untouched(): void
    {
        $path = $this->xlsxFixture([
            'Summary by LEA' => [
                ['AUN', 'LEA Name', 'County', 'Enrollment Grades 7-12', 'Male Dropouts', 'Female Dropouts', 'Dropouts', 'Dropout Rate'],
                [124157203, 'Phoenixville Area SD', 'Chester', 500, 3, 2, 5, 0.01],
            ],
        ]);

        $table = (new DropoutsParser(new SpreadsheetReader()))->parse($path);

        $this->assertSame(['name' => 'Phoenixville Area SD', 'county' => 'Chester', 'lea_type' => null], $table->districts['124157203']);

        $row = $table->rows['124157203'][0];
        $this->assertSame(500.0, $row['enrollment']);
        $this->assertSame(3.0, $row['male_dropouts']);
        $this->assertSame(2.0, $row['female_dropouts']);
        $this->assertSame(5.0, $row['dropouts']);
        // PDE's own reported rate is kept exactly, not recomputed, for an
        // already-per-LEA sheet.
        $this->assertSame(0.01, $row['rate']);
    }

    public function test_aggregates_per_school_rows_into_one_district_row_and_recomputes_the_rate(): void
    {
        $path = $this->xlsxFixture([
            'Summary by School_5' => [
                ['AUN', 'LEA Name', 'County', 'School Name', 'Enrollment Grades 7-12', 'Male Dropouts', 'Female Dropouts', 'Dropouts', 'Dropout Rate'],
                [124157203, 'Phoenixville Area SD', 'Chester', 'Elementary A', 300, 2, 1, 3, 0.5],
                ['', '', '', 'Elementary B', 200, 1, 1, 2, 0.5],
                // Synthetic per-district total row PDE appends on per-school
                // sheets - blank AUN AND blank school - must be skipped
                // entirely, not summed in as a third "school".
                ['', '', '', '', '', '', '', '', ''],
            ],
        ]);

        $table = (new DropoutsParser(new SpreadsheetReader()))->parse($path);

        $this->assertCount(1, $table->rows['124157203']);

        $row = $table->rows['124157203'][0];
        $this->assertSame(500.0, $row['enrollment']);
        $this->assertSame(3.0, $row['male_dropouts']);
        $this->assertSame(2.0, $row['female_dropouts']);
        $this->assertSame(5.0, $row['dropouts']);
        $this->assertSame(0.01, $row['rate']); // recomputed: 5 / 500, not the per-school 0.5 values
        $this->assertSame('Phoenixville Area SD', $table->districts['124157203']['name']);
    }

    public function test_throws_when_no_sheet_has_an_aun_like_column_with_the_expected_fields(): void
    {
        $path = $this->xlsxFixture([
            'Notes' => [
                ['Some', 'Other', 'Sheet'],
                ['a', 'b', 'c'],
            ],
        ]);

        $this->expectException(PDEClientException::class);
        $this->expectExceptionMessage('has a header row with an AUN-like column');

        (new DropoutsParser(new SpreadsheetReader()))->parse($path);
    }
}
