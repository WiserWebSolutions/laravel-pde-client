<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\RemoteFile;

class RemoteFileTest extends TestCase
{
    public function test_filename_url_decodes_the_basename(): void
    {
        $file = new RemoteFile(
            label: 'AFR 2024-2025',
            url: 'https://example.com/content/dam/afr%202024-2025%2C%20final.xlsx',
        );

        $this->assertSame('afr 2024-2025, final.xlsx', $file->filename());
    }

    public function test_extension_reads_from_the_decoded_filename(): void
    {
        $file = new RemoteFile(label: 'x', url: 'https://example.com/path/report%2Efinal.xls');

        $this->assertSame('xls', $file->extension());
    }

    public function test_to_array_includes_filename_and_all_fields(): void
    {
        $file = new RemoteFile(
            label: 'Revenues 2024-2025',
            url: 'https://example.com/dam/revenues%202024-2025.xlsx',
            category: 'Revenues',
            period: '2024-2025',
        );

        $this->assertSame([
            'label' => 'Revenues 2024-2025',
            'url' => 'https://example.com/dam/revenues%202024-2025.xlsx',
            'category' => 'Revenues',
            'period' => '2024-2025',
            'filename' => 'revenues 2024-2025.xlsx',
        ], $file->toArray());
    }

    public function test_from_array_round_trips_exactly(): void
    {
        $original = new RemoteFile(
            label: 'Revenues 2024-2025',
            url: 'https://example.com/dam/revenues%202024-2025.xlsx',
            category: 'Revenues',
            period: '2024-2025',
        );

        $rehydrated = RemoteFile::fromArray($original->toArray());

        $this->assertEquals($original, $rehydrated);
        $this->assertSame($original->toArray(), $rehydrated->toArray());
    }

    public function test_from_array_defaults_missing_optional_fields_to_null(): void
    {
        $file = RemoteFile::fromArray([
            'label' => 'A file',
            'url' => 'https://example.com/a.xlsx',
        ]);

        $this->assertNull($file->category);
        $this->assertNull($file->period);
    }
}
