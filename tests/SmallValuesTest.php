<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function sprintf;

#[CoversClass(Count::class)]
#[CoversClass(Until::class)]
#[CoversClass(Weekday::class)]
#[CoversClass(WeekdayNum::class)]
final class SmallValuesTest extends TestCase
{

    public function testCount(): void
    {
        self::assertSame(1, Count::of(1)->value);
        self::assertSame(500, Count::of(500)->value);
    }

    public function testCountMustBePositive(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('COUNT must be a positive integer, 0 given');

        Count::of(0);
    }

    public function testUntil(): void
    {
        $date = LocalDate::of(2026, 1, 31);
        $localDateTime = LocalDateTime::of(2026, 1, 31, 9);

        self::assertSame($date, Until::date($date)->value);
        self::assertSame($localDateTime, Until::local($localDateTime)->value);

        $instant = Until::instant(new DateTimeImmutable('2026-01-31 10:00:00.750', new DateTimeZone('Europe/Prague')))->value;
        self::assertInstanceOf(DateTimeImmutable::class, $instant);
        self::assertSame('2026-01-31T09:00:00.000000+00:00', $instant->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('UTC', $instant->getTimezone()->getName());
    }

    public function testUntilInstantOutOfRange(): void
    {
        $this->expectException(InvalidValueException::class);

        Until::instant(new DateTimeImmutable('@253402300800')); // 10000-01-01T00:00:00Z
    }

    public function testWeekday(): void
    {
        $numbers = [];

        foreach (Weekday::cases() as $weekday) {
            $numbers[$weekday->value] = $weekday->getIsoNumber();
            self::assertSame($weekday, Weekday::fromIsoNumber($weekday->getIsoNumber()));
        }

        self::assertSame(['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7], $numbers);
    }

    public function testWeekdayNum(): void
    {
        self::assertSame('MO', WeekdayNum::every(Weekday::Monday)->toString());
        self::assertSame('MO', WeekdayNum::of(Weekday::Monday)->toString());
        self::assertSame('2TU', WeekdayNum::of(Weekday::Tuesday, 2)->toString());
        self::assertSame('-1FR', WeekdayNum::of(Weekday::Friday, -1)->toString());
        self::assertSame('53SU', WeekdayNum::of(Weekday::Sunday, 53)->toString());
        self::assertSame('-53SA', WeekdayNum::of(Weekday::Saturday, -53)->toString());
        self::assertTrue(WeekdayNum::of(Weekday::Friday, -1)->equals(WeekdayNum::of(Weekday::Friday, -1)));
        self::assertFalse(WeekdayNum::of(Weekday::Friday, -1)->equals(WeekdayNum::of(Weekday::Friday, 1)));
        self::assertFalse(WeekdayNum::of(Weekday::Friday, -1)->equals(WeekdayNum::of(Weekday::Thursday, -1)));
        self::assertNull(WeekdayNum::every(Weekday::Monday)->ordinal);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidOrdinals(): iterable
    {
        yield 'zero' => [0];
        yield '54' => [54];
        yield '-54' => [-54];
    }

    #[DataProvider('provideInvalidOrdinals')]
    public function testWeekdayNumInvalidOrdinal(int $ordinal): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage(sprintf('Invalid BYDAY ordinal %d (allowed: -53 to -1 and 1 to 53)', $ordinal));

        WeekdayNum::of(Weekday::Monday, $ordinal);
    }

    #[DataProvider('provideInvalidIsoWeekdays')]
    public function testInvalidIsoWeekday(int $isoNumber): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ISO weekday number $isoNumber is not from 1 (Monday) to 7 (Sunday)");

        Weekday::fromIsoNumber($isoNumber); // @phpstan-ignore argument.type (tests the runtime check for callers without static analysis), staticMethod.resultUnused (the test expects the call to throw)
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidIsoWeekdays(): iterable
    {
        yield 'zero' => [0];
        yield 'eight' => [8];
    }

}
