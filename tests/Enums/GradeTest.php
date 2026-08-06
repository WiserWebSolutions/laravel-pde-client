<?php

namespace WiserWebSolutions\PDEClient\Tests\Enums;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\Enums\Grade;

class GradeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function pkSubcodesProvider(): iterable
    {
        foreach (['PKA', 'PKP', 'PKF', 'PreK'] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('pkSubcodesProvider')]
    public function test_normalize_collapses_pk_subcodes(string $code): void
    {
        $this->assertSame('PK', Grade::normalize($code));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function kindergartenSubcodesProvider(): iterable
    {
        foreach (['K4A', 'K4P', 'K4F', 'K5A', 'K5P', 'K5F', 'K', 'K4', 'K5'] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('kindergartenSubcodesProvider')]
    public function test_normalize_collapses_kindergarten_subcodes(string $code): void
    {
        $this->assertSame('K', Grade::normalize($code));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function numericGradesProvider(): iterable
    {
        foreach (range(1, 12) as $grade) {
            yield "padded {$grade}" => [str_pad((string) $grade, 3, '0', STR_PAD_LEFT), (string) $grade];
            yield "bare {$grade}" => [(string) $grade, (string) $grade];
        }
    }

    #[DataProvider('numericGradesProvider')]
    public function test_normalize_unpads_numeric_grades(string $raw, string $expected): void
    {
        $this->assertSame($expected, Grade::normalize($raw));
    }

    public function test_normalize_passes_through_unrecognized_codes_untouched(): void
    {
        $this->assertSame('Ungraded', Grade::normalize('Ungraded'));
        $this->assertSame('AE', Grade::normalize('AE'));
    }

    public function test_normalize_trims_whitespace(): void
    {
        $this->assertSame('K', Grade::normalize('  K  '));
    }

    public function test_sort_index_orders_pk_before_k_before_numeric_grades(): void
    {
        $indexes = [
            'PK' => Grade::sortIndex('PK'),
            'K' => Grade::sortIndex('K'),
            '1' => Grade::sortIndex('1'),
            '2' => Grade::sortIndex('2'),
            '12' => Grade::sortIndex('12'),
        ];

        $this->assertLessThan($indexes['K'], $indexes['PK']);
        $this->assertLessThan($indexes['1'], $indexes['K']);
        $this->assertLessThan($indexes['2'], $indexes['1']);
        $this->assertLessThan($indexes['12'], $indexes['2']);
    }

    public function test_sort_index_sorts_unrecognized_codes_last(): void
    {
        $unrecognized = Grade::sortIndex('Ungraded');

        $this->assertGreaterThan(Grade::sortIndex('12'), $unrecognized);
    }
}
