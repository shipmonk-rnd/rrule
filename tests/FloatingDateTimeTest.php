<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\FloatingDateTime;
use function sprintf;

#[CoversClass(FloatingDateTime::class)]
final class FloatingDateTimeTest extends TestCase
{

    public function testOf(): void
    {
        $dateTime = FloatingDateTime::of(2026, 1, 31, 9, 5, 7);

        self::assertSame([2026, 1, 31, 9, 5, 7], [$dateTime->year, $dateTime->month, $dateTime->day, $dateTime->hour, $dateTime->minute, $dateTime->second]);
        self::assertSame('2026-01-31T09:05:07', $dateTime->toString());
        self::assertSame('2026-01-31T00:00:00', FloatingDateTime::of(2026, 1, 31)->toString());
        self::assertSame('2026-01-31', $dateTime->getDate()->toString());
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
    ): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage(sprintf('Invalid date-time %04d-%02d-%02dT%02d:%02d:%02d (years 1 to 9999 are supported)', $year, $month, $day, $hour, $minute, $second));

        FloatingDateTime::of($year, $month, $day, $hour, $minute, $second);
    }

    /**
     * @return iterable<string, array{int, int, int, int, int, int}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'invalid date' => [2026, 2, 30, 0, 0, 0];
        yield 'hour 24' => [2026, 1, 1, 24, 0, 0];
        yield 'hour -1' => [2026, 1, 1, -1, 0, 0];
        yield 'minute 60' => [2026, 1, 1, 0, 60, 0];
        yield 'minute -1' => [2026, 1, 1, 0, -1, 0];
        yield 'second 60' => [2026, 1, 1, 0, 0, 60];
        yield 'second -1' => [2026, 1, 1, 0, 0, -1];
    }

    public function testBoundaries(): void
    {
        self::assertSame('2026-01-01T23:59:59', FloatingDateTime::of(2026, 1, 1, 23, 59, 59)->toString());
    }

    public function testFromDateTimeUsesTheWallClockTime(): void
    {
        $dateTime = new DateTimeImmutable('2026-01-31 23:30:15.250', new DateTimeZone('America/New_York'));

        self::assertSame('2026-01-31T23:30:15', FloatingDateTime::fromDateTime($dateTime)->toString());
    }

    /**
     * @param array{int, int, int, int, int, int} $local
     * @param string                              $expected ISO 8601 with offset
     */
    #[DataProvider('provideTimeZoneResolution')]
    public function testAtTimeZone(
        array $local,
        string $zone,
        string $expected,
    ): void
    {
        $instant = FloatingDateTime::of(...$local)->atTimeZone(new DateTimeZone($zone));

        self::assertSame($expected, $instant->format('Y-m-d\TH:i:sP'));
        self::assertSame($zone, $instant->getTimezone()->getName());
    }

    /**
     * @return iterable<string, array{array{int, int, int, int, int, int}, string, string}>
     */
    public static function provideTimeZoneResolution(): iterable
    {
        yield 'gap: offset before the gap (RFC 5545 3.3.5 example)' => [[2007, 3, 11, 2, 30, 0], 'America/New_York', '2007-03-11T03:30:00-04:00'];
        yield 'overlap: first occurrence (RFC 5545 3.3.5 example)' => [[2007, 11, 4, 1, 30, 0], 'America/New_York', '2007-11-04T01:30:00-04:00'];
        yield 'overlap in Prague, where PHP picks the second occurrence' => [[2026, 10, 25, 2, 30, 0], 'Europe/Prague', '2026-10-25T02:30:00+02:00'];
        yield 'ordinary time' => [[2026, 7, 1, 12, 0, 0], 'Europe/Prague', '2026-07-01T12:00:00+02:00'];
    }

    public function testLocalSeconds(): void
    {
        $dateTime = FloatingDateTime::of(1969, 12, 31, 23, 59, 59);

        self::assertSame(-1, $dateTime->toLocalSeconds());
        self::assertSame('1969-12-31T23:59:59', FloatingDateTime::fromLocalSeconds(-1)->toString());
        self::assertSame('1970-01-01T01:00:00', FloatingDateTime::fromLocalSeconds(3600)->toString());
        self::assertSame('1970-01-01T00:59:59', FloatingDateTime::fromLocalSeconds(3599)->toString());
        self::assertSame('1970-01-01T00:01:00', FloatingDateTime::fromLocalSeconds(60)->toString());
        self::assertSame('1970-01-01T00:00:59', FloatingDateTime::fromLocalSeconds(59)->toString());
        self::assertSame('2026-01-31T09:05:07', FloatingDateTime::fromLocalSeconds(FloatingDateTime::of(2026, 1, 31, 9, 5, 7)->toLocalSeconds())->toString());
    }

    public function testComparison(): void
    {
        $dateTime = FloatingDateTime::of(2026, 3, 15, 10);

        self::assertSame(0, $dateTime->compareTo(FloatingDateTime::of(2026, 3, 15, 10)));
        self::assertSame(-1, $dateTime->compareTo(FloatingDateTime::of(2026, 3, 15, 10, 0, 1)));
        self::assertSame(1, $dateTime->compareTo(FloatingDateTime::of(2026, 3, 15, 9, 59, 59)));
        self::assertTrue($dateTime->equals(FloatingDateTime::of(2026, 3, 15, 10)));
        self::assertFalse($dateTime->equals(FloatingDateTime::of(2026, 3, 15, 11)));
        self::assertTrue($dateTime->isBefore(FloatingDateTime::of(2026, 3, 15, 11)));
        self::assertFalse($dateTime->isBefore($dateTime));
        self::assertTrue($dateTime->isAfter(FloatingDateTime::of(2026, 3, 15, 9)));
        self::assertFalse($dateTime->isAfter($dateTime));
    }

}
