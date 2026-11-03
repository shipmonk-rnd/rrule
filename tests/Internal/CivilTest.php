<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Internal;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\Internal\Civil;
use function intdiv;

#[CoversClass(Civil::class)]
final class CivilTest extends TestCase
{

    public function testDayNumbersMatchPhpForEveryDayOfFourCenturies(): void
    {
        $firstDay = intdiv((new DateTimeImmutable('1800-01-01', new DateTimeZone('UTC')))->getTimestamp(), Civil::SECONDS_PER_DAY);

        for ($i = 0; $i < Civil::DAYS_PER_400_YEARS; $i += 7) {
            $current = new DateTimeImmutable('@' . (($firstDay + $i) * Civil::SECONDS_PER_DAY));
            $year = (int) $current->format('Y');
            $month = (int) $current->format('n');
            $day = (int) $current->format('j');
            $days = Civil::daysFromCivil($year, $month, $day);

            self::assertSame($firstDay + $i, $days);
            self::assertSame([$year, $month, $day], Civil::civilFromDays($days));
            self::assertSame((int) $current->format('N') - 1, Civil::weekdayIndex($days));
        }
    }

    public function testExtremeYears(): void
    {
        self::assertSame(-719_162, Civil::daysFromCivil(1, 1, 1));
        self::assertSame([1, 1, 1], Civil::civilFromDays(-719_162));
        self::assertSame(0, Civil::weekdayIndex(-719_162)); // 0001-01-01 was a Monday (proleptic Gregorian)
        self::assertSame(2_932_896, Civil::daysFromCivil(9999, 12, 31));
        self::assertSame([9999, 12, 31], Civil::civilFromDays(2_932_896));
        self::assertSame(4, Civil::weekdayIndex(2_932_896)); // 9999-12-31 is a Friday
        self::assertSame([0, 12, 31], Civil::civilFromDays(-719_163));
        self::assertSame([-1, 3, 1], Civil::civilFromDays(Civil::daysFromCivil(-1, 3, 1)));
    }

    #[DataProvider('provideLeapYears')]
    public function testLeapYears(
        int $year,
        bool $leap,
    ): void
    {
        self::assertSame($leap, Civil::isLeapYear($year));
        self::assertSame($leap ? 366 : 365, Civil::daysInYear($year));
        self::assertSame($leap ? 29 : 28, Civil::daysInMonth($year, 2));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideLeapYears(): iterable
    {
        yield '1900' => [1900, false];
        yield '2000' => [2000, true];
        yield '2024' => [2024, true];
        yield '2026' => [2026, false];
        yield '2100' => [2100, false];
        yield '2400' => [2400, true];
    }

    public function testDaysInMonth(): void
    {
        $lengths = [];

        for ($month = 1; $month <= 12; $month++) {
            $lengths[] = Civil::daysInMonth(2026, $month);
        }

        self::assertSame([31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31], $lengths);
    }

    #[DataProvider('provideValidDates')]
    public function testIsValidDate(
        int $year,
        int $month,
        int $day,
        bool $valid,
    ): void
    {
        self::assertSame($valid, Civil::isValidDate($year, $month, $day));
    }

    /**
     * @return iterable<string, array{int, int, int, bool}>
     */
    public static function provideValidDates(): iterable
    {
        yield 'first day' => [1, 1, 1, true];
        yield 'last day' => [9999, 12, 31, true];
        yield 'year 0' => [0, 12, 31, false];
        yield 'year 10000' => [10_000, 1, 1, false];
        yield 'month 0' => [2026, 0, 1, false];
        yield 'month 13' => [2026, 13, 1, false];
        yield 'day 0' => [2026, 1, 0, false];
        yield 'February 29 in a leap year' => [2024, 2, 29, true];
        yield 'February 29 in a common year' => [2026, 2, 29, false];
        yield 'April 31' => [2026, 4, 31, false];
        yield 'April 30' => [2026, 4, 30, true];
    }

    #[DataProvider('provideFloorDivision')]
    public function testFloorDivision(
        int $dividend,
        int $divisor,
        int $quotient,
        int $remainder,
    ): void
    {
        self::assertSame($quotient, Civil::floorDiv($dividend, $divisor));
        self::assertSame($remainder, Civil::floorMod($dividend, $divisor));
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function provideFloorDivision(): iterable
    {
        yield '7 / 2' => [7, 2, 3, 1];
        yield '-7 / 2' => [-7, 2, -4, 1];
        yield '7 / -2' => [7, -2, -4, -1];
        yield '-7 / -2' => [-7, -2, 3, -1];
        yield '-8 / 2' => [-8, 2, -4, 0];
        yield '0 / 5' => [0, 5, 0, 0];
        yield '-1 / 86400' => [-1, 86_400, -1, 86_399];
    }

    public function testLocalSeconds(): void
    {
        self::assertSame(86_400 * 3 + 3600 * 4 + 60 * 5 + 6, Civil::localSeconds(3, 4, 5, 6));
        self::assertSame(-86_400 + 59, Civil::localSeconds(-1, 0, 0, 59));
    }

}
