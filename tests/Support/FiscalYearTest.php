<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\FiscalYear;

class FiscalYearTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|int}>
     */
    public static function equivalentFormatsProvider(): iterable
    {
        yield 'short' => ['2024-25'];
        yield 'long' => ['2024-2025'];
        yield 'spaced' => ['2024 - 2025'];
        yield 'bare string' => ['2024'];
        yield 'bare int' => [2024];
    }

    #[DataProvider('equivalentFormatsProvider')]
    public function test_parses_equivalent_formats_to_the_same_start_year(string|int $value): void
    {
        $year = FiscalYear::parse($value);

        $this->assertSame(2024, $year->startYear);
    }

    public function test_parse_is_idempotent_for_an_existing_instance(): void
    {
        $year = FiscalYear::parse('2024-25');

        $this->assertSame($year, FiscalYear::parse($year));
    }

    public function test_short_renders_two_digit_end_year(): void
    {
        $this->assertSame('2024-25', FiscalYear::parse('2024')->short());
    }

    public function test_long_renders_four_digit_end_year(): void
    {
        $this->assertSame('2024-2025', FiscalYear::parse('2024')->long());
    }

    public function test_to_string_renders_the_long_form(): void
    {
        $this->assertSame('2024-2025', (string) FiscalYear::parse('2024-25'));
    }

    public function test_equals_compares_by_start_year_only(): void
    {
        $a = FiscalYear::parse('2024-25');
        $b = FiscalYear::parse('2024-2025');
        $c = FiscalYear::parse('2023-24');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function test_throws_on_garbage_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unrecognized fiscal year');

        FiscalYear::parse('not-a-year');
    }

    public function test_throws_on_implausible_start_year(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Implausible fiscal year start');

        FiscalYear::parse('1899');
    }
}
