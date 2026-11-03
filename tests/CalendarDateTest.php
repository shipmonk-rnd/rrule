<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Weekday;

#[CoversClass(CalendarDate::class)]
final class CalendarDateTest extends TestCase
{

    public function testOf(): void
    {
        $date = CalendarDate::of(2026, 1, 31);

        self::assertSame(2026, $date->year);
        self::assertSame(1, $date->month);
        self::assertSame(31, $date->day);
        self::assertSame('2026-01-31', $date->toString());
        self::assertSame('0001-01-01', CalendarDate::of(1, 1, 1)->toString());
        self::assertSame('9999-12-31', CalendarDate::of(9999, 12, 31)->toString());
    }

    #[DataProvider('provideInvalidDates')]
    public function testInvalid(
        int $year,
        int $month,
        int $day,
        string $message,
    ): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage($message);

        CalendarDate::of($year, $month, $day);
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function provideInvalidDates(): iterable
    {
        yield 'February 29 in a common year' => [2026, 2, 29, 'Invalid date 2026-02-29 (years 1 to 9999 are supported)'];
        yield 'year 0' => [0, 1, 1, 'Invalid date 0000-01-01 (years 1 to 9999 are supported)'];
        yield 'year 10000' => [10_000, 1, 1, 'Invalid date 10000-01-01 (years 1 to 9999 are supported)'];
        yield 'month 13' => [2026, 13, 1, 'Invalid date 2026-13-01 (years 1 to 9999 are supported)'];
    }

    public function testFromDateTimeUsesTheWallClockDate(): void
    {
        $dateTime = new DateTimeImmutable('2026-01-31 23:30:00', new DateTimeZone('America/New_York'));

        self::assertSame('2026-01-31', CalendarDate::fromDateTime($dateTime)->toString());
    }

    public function testDayOfWeek(): void
    {
        self::assertSame(Weekday::Saturday, CalendarDate::of(2026, 1, 31)->getDayOfWeek());
        self::assertSame(Weekday::Monday, CalendarDate::of(2026, 2, 2)->getDayOfWeek());
        self::assertSame(Weekday::Sunday, CalendarDate::of(2026, 2, 1)->getDayOfWeek());
    }

    public function testAtTime(): void
    {
        self::assertSame('2026-01-31T09:05:07', CalendarDate::of(2026, 1, 31)->atTime(9, 5, 7)->toString());
        self::assertSame('2026-01-31T09:00:00', CalendarDate::of(2026, 1, 31)->atTime(9)->toString());
    }

    public function testAtStartOfDayResolvesAMissingMidnight(): void
    {
        $saoPaulo = new DateTimeZone('America/Sao_Paulo');

        self::assertSame('2018-11-04T01:00:00-02:00', CalendarDate::of(2018, 11, 4)->atStartOfDayIn($saoPaulo)->format('c'));
        self::assertSame('2018-11-05T00:00:00-02:00', CalendarDate::of(2018, 11, 5)->atStartOfDayIn($saoPaulo)->format('c'));
        self::assertSame('America/Sao_Paulo', CalendarDate::of(2018, 11, 5)->atStartOfDayIn($saoPaulo)->getTimezone()->getName());
    }

    public function testComparison(): void
    {
        $date = CalendarDate::of(2026, 3, 15);

        self::assertSame(0, $date->compareTo(CalendarDate::of(2026, 3, 15)));
        self::assertSame(-1, $date->compareTo(CalendarDate::of(2026, 3, 16)));
        self::assertSame(1, $date->compareTo(CalendarDate::of(2026, 2, 28)));
        self::assertSame(1, $date->compareTo(CalendarDate::of(2025, 12, 31)));
        self::assertTrue($date->equals(CalendarDate::of(2026, 3, 15)));
        self::assertFalse($date->equals(CalendarDate::of(2026, 3, 14)));
        self::assertTrue($date->isBefore(CalendarDate::of(2026, 4, 1)));
        self::assertFalse($date->isBefore($date));
        self::assertTrue($date->isAfter(CalendarDate::of(2026, 3, 14)));
        self::assertFalse($date->isAfter($date));
    }

    public function testDayNumbers(): void
    {
        self::assertSame(0, CalendarDate::of(1970, 1, 1)->toDayNumber());
        self::assertSame(-1, CalendarDate::of(1969, 12, 31)->toDayNumber());
        self::assertSame('2026-01-31', CalendarDate::fromDayNumber(20_484)->toString());
    }

    public function testDayNumberRange(): void
    {
        self::assertSame('1970-01-01', CalendarDate::fromDayNumber(0)->toString());
        self::assertSame(20_454, CalendarDate::of(2026, 1, 1)->toDayNumber());
        self::assertSame('9999-12-31', CalendarDate::fromDayNumber(2_932_896)->toString());
        self::assertSame('0001-01-01', CalendarDate::fromDayNumber(-719_162)->toString());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Day number 2932897 is outside the years 1 to 9999');
        CalendarDate::fromDayNumber(2_932_897);
    }

}
