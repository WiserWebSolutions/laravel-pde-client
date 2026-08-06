<?php

namespace WiserWebSolutions\PDEClient\Tests;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use WiserWebSolutions\PDEClient\Contracts\FileDownloader;
use WiserWebSolutions\PDEClient\Enrollment\EnrollmentFiles;
use WiserWebSolutions\PDEClient\Enrollment\Finders\EnrollmentFileFinder;
use WiserWebSolutions\PDEClient\Exceptions\DataSetNotFoundException;
use WiserWebSolutions\PDEClient\RemoteFile;

/**
 * DataSource is abstract - tested through EnrollmentFiles, a minimal
 * concrete subclass, with the Finder/Downloader dependencies faked directly
 * (EnrollmentFiles' constructor is typed to the concrete EnrollmentFileFinder,
 * not the FileFinder interface, so that's what gets mocked) so this stays
 * about DataSource's own filtering/download logic, not any real HTTP or
 * Finder-specific parsing (see EnrollmentIntegrationTest for that).
 */
#[AllowMockObjectsWithoutExpectations]
class DataSourceTest extends TestCase
{
    public function test_category_filters_case_insensitively(): void
    {
        $files = $this->makeSource($this->fakeFinder())->category('PUBLIC')->get();

        $this->assertCount(1, $files);
        $this->assertSame('public', $files->first()->category);
    }

    public function test_matching_filters_by_label_substring_case_insensitively(): void
    {
        $files = $this->makeSource($this->fakeFinder())->matching('projections')->get();

        $this->assertCount(1, $files);
        $this->assertStringContainsString('Projections', $files->first()->label);
    }

    public function test_category_and_matching_combine(): void
    {
        $files = $this->makeSource($this->fakeFinder())->category('public')->matching('2024')->get();

        $this->assertCount(1, $files);
    }

    public function test_first_returns_the_first_matched_file_or_null(): void
    {
        $this->assertNotNull($this->makeSource($this->fakeFinder())->category('public')->first());
        $this->assertNull($this->makeSource($this->fakeFinder())->category('nonexistent')->first());
    }

    public function test_sole_throws_when_no_files_match(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        $this->makeSource($this->fakeFinder())->category('nonexistent')->sole();
    }

    public function test_sole_throws_when_more_than_one_file_matches(): void
    {
        $this->expectException(DataSetNotFoundException::class);

        // "public" category alone matches two fixture files (2023 and 2024).
        $this->makeSource($this->fakeFinderWithTwoPublicFiles())->category('public')->sole();
    }

    public function test_allFiles_is_memoized_so_the_finder_is_called_at_most_once_per_instance(): void
    {
        $finder = $this->createMock(EnrollmentFileFinder::class);
        $finder->expects($this->once())->method('find')->willReturn($this->fixtureFiles());

        $downloader = $this->createMock(FileDownloader::class);
        $source = new EnrollmentFiles($finder, $downloader);

        // Three separate terminal calls on the same instance - the Finder
        // must still only be asked once.
        $source->category('public')->get();
        $source->category('projections')->get();
        $source->first();
    }

    public function test_download_delegates_to_the_file_downloader_for_every_matched_file(): void
    {
        $downloader = $this->createMock(FileDownloader::class);
        $downloader->expects($this->once())
            ->method('download')
            ->with($this->isInstanceOf(RemoteFile::class), $this->stringContains('public-2024.xlsx'), null)
            ->willReturn('stored/path.xlsx');

        $source = new EnrollmentFiles($this->fakeFinder(), $downloader);

        $paths = $source->category('public')->matching('2024')->download();

        $this->assertSame(['stored/path.xlsx'], $paths->all());
    }

    private function makeSource(EnrollmentFileFinder $finder): EnrollmentFiles
    {
        return new EnrollmentFiles($finder, $this->createMock(FileDownloader::class));
    }

    private function fakeFinder(): EnrollmentFileFinder
    {
        $finder = $this->createMock(EnrollmentFileFinder::class);
        $finder->method('find')->willReturn($this->fixtureFiles());

        return $finder;
    }

    private function fakeFinderWithTwoPublicFiles(): EnrollmentFileFinder
    {
        $finder = $this->createMock(EnrollmentFileFinder::class);
        $finder->method('find')->willReturn(collect([
            ...$this->fixtureFiles(),
            new RemoteFile(label: '2023-2024 Public School Enrollment', url: 'https://example.test/public-2023.xlsx', category: 'public', period: '2023-2024'),
        ]));

        return $finder;
    }

    /**
     * @return Collection<int, RemoteFile>
     */
    private function fixtureFiles(): Collection
    {
        return collect([
            new RemoteFile(label: '2024-2025 Public School Enrollment', url: 'https://example.test/public-2024.xlsx', category: 'public', period: '2024-2025'),
            new RemoteFile(label: 'School District Enrollment Projections', url: 'https://example.test/projections.xlsx', category: 'projections', period: null),
        ]);
    }
}
