<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\CalendarScale;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\Exception\InvalidRecurrenceRuleException;
use ShipMonk\RRule\Exception\RecurrenceException;
use ShipMonk\RRule\Exception\RuleViolation;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use Throwable;

#[CoversClass(RecurrenceRule::class)]
#[CoversClass(InvalidRecurrenceRuleException::class)]
final class RecurrenceRuleTest extends TestCase
{

    public function testDefaults(): void
    {
        $rule = new RecurrenceRule(Frequency::Daily);

        self::assertSame(Frequency::Daily, $rule->frequency);
        self::assertSame(1, $rule->interval);
        self::assertNull($rule->end);
        self::assertSame([], $rule->bySecond);
        self::assertSame([], $rule->byMinute);
        self::assertSame([], $rule->byHour);
        self::assertSame([], $rule->byDay);
        self::assertSame([], $rule->byMonthDay);
        self::assertSame([], $rule->byYearDay);
        self::assertSame([], $rule->byWeekNo);
        self::assertSame([], $rule->byMonth);
        self::assertSame([], $rule->bySetPos);
        self::assertSame(Weekday::Monday, $rule->weekStart);
        self::assertNull($rule->calendarScale);
        self::assertSame(Skip::Omit, $rule->skip);
        self::assertSame('FREQ=DAILY', $rule->toString());
    }

    public function testFromString(): void
    {
        $rule = RecurrenceRule::fromString('FREQ=MONTHLY;BYDAY=-1FR;COUNT=3');

        self::assertSame(Frequency::Monthly, $rule->frequency);
        self::assertSame('FREQ=MONTHLY;COUNT=3;BYDAY=-1FR', $rule->toString());
    }

    public function testEquals(): void
    {
        $rule = RecurrenceRule::fromString('FREQ=WEEKLY;BYDAY=MO,WE');

        self::assertTrue($rule->equals(RecurrenceRule::fromString('BYDAY=MO,WE;FREQ=WEEKLY;WKST=MO')));
        self::assertFalse($rule->equals(RecurrenceRule::fromString('FREQ=WEEKLY;BYDAY=WE,MO')));
    }

    public function testWithers(): void
    {
        $rule = (new RecurrenceRule(Frequency::Yearly))
            ->withInterval(2)
            ->withEnd(Count::of(5))
            ->withBySecond([0, 30])
            ->withByMinute([15])
            ->withByHour([9])
            ->withByDay([WeekdayNum::of(Weekday::Monday, 1)])
            ->withByMonthDay([1, -1])
            ->withByYearDay([100])
            ->withByMonth([3])
            ->withBySetPos([1])
            ->withWeekStart(Weekday::Sunday)
            ->withSkip(Skip::Backward);

        self::assertSame(
            'FREQ=YEARLY;RSCALE=GREGORIAN;INTERVAL=2;COUNT=5;BYSECOND=0,30;BYMINUTE=15;BYHOUR=9;BYDAY=1MO;BYMONTHDAY=1,-1;BYYEARDAY=100;BYMONTH=3;BYSETPOS=1;WKST=SU;SKIP=BACKWARD',
            $rule->toString(),
        );
        self::assertSame('FREQ=YEARLY;BYWEEKNO=20', (new RecurrenceRule(Frequency::Yearly))->withByWeekNo([20])->toString());
        self::assertSame('FREQ=YEARLY;INTERVAL=2;COUNT=5', $rule->withSkip(Skip::Omit)->withBySetPos([])->withBySecond([])->withByMinute([])->withByHour([])->withByDay([])->withByMonthDay([])->withByYearDay([])->withByMonth([])->withWeekStart(Weekday::Monday)->toString());
        self::assertSame('FREQ=YEARLY;INTERVAL=2;BYSECOND=0,30;BYMINUTE=15;BYHOUR=9;BYDAY=1MO;BYMONTHDAY=1,-1;BYYEARDAY=100;BYMONTH=3;BYSETPOS=1;WKST=SU', $rule->withEnd(null)->withSkip(Skip::Omit)->toString());
        self::assertSame('FREQ=YEARLY;UNTIL=20260101', (new RecurrenceRule(Frequency::Yearly))->withEnd(Until::date(CalendarDate::of(2026, 1, 1)))->toString());
        self::assertSame('FREQ=YEARLY;COUNT=5', (new RecurrenceRule(Frequency::Yearly, end: Count::of(5)))->withInterval(1)->toString(), 'withInterval keeps the end');
    }

    /**
     * @param callable(): RecurrenceRule $create
     */
    #[DataProvider('provideInvalidRules')]
    public function testInvalid(
        callable $create,
        RuleViolation $violation,
        string $message,
    ): void
    {
        try {
            $create();
            self::fail('Exception expected');
        } catch (InvalidRecurrenceRuleException $e) {
            self::assertSame($violation, $e->violation);
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{callable(): RecurrenceRule, RuleViolation, string}>
     */
    public static function provideInvalidRules(): iterable
    {
        yield 'interval 0' => [
            static fn () => new RecurrenceRule(Frequency::Daily, interval: 0), // @phpstan-ignore argument.type
            RuleViolation::IntervalNotPositive,
            'INTERVAL must be a positive integer, 0 given',
        ];
        yield 'BYSECOND 61' => [
            static fn () => new RecurrenceRule(Frequency::Daily, bySecond: [61]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYSECOND value 61 is out of range (allowed: 0 to 60)',
        ];
        yield 'BYMINUTE -1' => [
            static fn () => new RecurrenceRule(Frequency::Daily, byMinute: [-1]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYMINUTE value -1 is out of range (allowed: 0 to 59)',
        ];
        yield 'BYHOUR 24' => [
            static fn () => new RecurrenceRule(Frequency::Daily, byHour: [24]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYHOUR value 24 is out of range (allowed: 0 to 23)',
        ];
        yield 'BYMONTHDAY 0' => [
            static fn () => new RecurrenceRule(Frequency::Monthly, byMonthDay: [0]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYMONTHDAY value 0 is out of range (allowed: -31 to 31, except 0)',
        ];
        yield 'BYMONTHDAY -32' => [
            static fn () => new RecurrenceRule(Frequency::Monthly, byMonthDay: [-32]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYMONTHDAY value -32 is out of range (allowed: -31 to 31, except 0)',
        ];
        yield 'BYYEARDAY 367' => [
            static fn () => new RecurrenceRule(Frequency::Yearly, byYearDay: [367]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYYEARDAY value 367 is out of range (allowed: -366 to 366, except 0)',
        ];
        yield 'BYWEEKNO 54' => [
            static fn () => new RecurrenceRule(Frequency::Yearly, byWeekNo: [54]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYWEEKNO value 54 is out of range (allowed: -53 to 53, except 0)',
        ];
        yield 'BYMONTH 13' => [
            static fn () => new RecurrenceRule(Frequency::Yearly, byMonth: [13]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYMONTH value 13 is out of range (allowed: 1 to 12)',
        ];
        yield 'BYSETPOS 0' => [
            static fn () => new RecurrenceRule(Frequency::Yearly, byMonth: [1], bySetPos: [0]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYSETPOS value 0 is out of range (allowed: -366 to 366, except 0)',
        ];
        yield 'non-integer value' => [
            static fn () => new RecurrenceRule(Frequency::Yearly, byMonth: ['1']), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYMONTH value of a non-integer type is out of range (allowed: 1 to 12)',
        ];
        yield 'not a list' => [
            static fn () => new RecurrenceRule(Frequency::Yearly, byMonth: [1 => 1]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYMONTH must be a list',
        ];
        yield 'BYDAY not a list' => [
            static fn () => new RecurrenceRule(Frequency::Weekly, byDay: [1 => WeekdayNum::every(Weekday::Monday)]), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYDAY must be a list',
        ];
        yield 'BYDAY not WeekdayNum' => [
            static fn () => new RecurrenceRule(Frequency::Weekly, byDay: ['MO']), // @phpstan-ignore argument.type
            RuleViolation::ValueOutOfRange,
            'BYDAY must contain WeekdayNum objects',
        ];
        yield 'BYDAY ordinal with WEEKLY' => [
            static fn () => new RecurrenceRule(Frequency::Weekly, byDay: [WeekdayNum::of(Weekday::Monday, 1)]),
            RuleViolation::ByDayOrdinalNotAllowed,
            'BYDAY value 1MO has an ordinal, which is allowed only with FREQ=MONTHLY or FREQ=YEARLY',
        ];
        yield 'BYDAY ordinal after a plain weekday' => [
            static fn () => new RecurrenceRule(Frequency::Weekly, byDay: [WeekdayNum::every(Weekday::Monday), WeekdayNum::of(Weekday::Friday, 1)]),
            RuleViolation::ByDayOrdinalNotAllowed,
            'BYDAY value 1FR has an ordinal, which is allowed only with FREQ=MONTHLY or FREQ=YEARLY',
        ];
        yield 'BYDAY ordinal with BYWEEKNO' => [
            static fn () => new RecurrenceRule(Frequency::Yearly, byDay: [WeekdayNum::of(Weekday::Monday, -1)], byWeekNo: [1]),
            RuleViolation::ByDayOrdinalNotAllowed,
            'BYDAY value -1MO has an ordinal, which is not allowed together with BYWEEKNO',
        ];
        yield 'BYWEEKNO with MONTHLY' => [
            static fn () => new RecurrenceRule(Frequency::Monthly, byWeekNo: [1]),
            RuleViolation::ByWeekNoRequiresYearly,
            'BYWEEKNO is allowed only with FREQ=YEARLY',
        ];
        yield 'BYYEARDAY with DAILY' => [
            static fn () => new RecurrenceRule(Frequency::Daily, byYearDay: [1]),
            RuleViolation::ByYearDayNotAllowed,
            'BYYEARDAY is not allowed with FREQ=DAILY',
        ];
        yield 'BYYEARDAY with WEEKLY' => [
            static fn () => new RecurrenceRule(Frequency::Weekly, byYearDay: [1]),
            RuleViolation::ByYearDayNotAllowed,
            'BYYEARDAY is not allowed with FREQ=WEEKLY',
        ];
        yield 'BYYEARDAY with MONTHLY' => [
            static fn () => new RecurrenceRule(Frequency::Monthly, byYearDay: [1]),
            RuleViolation::ByYearDayNotAllowed,
            'BYYEARDAY is not allowed with FREQ=MONTHLY',
        ];
        yield 'BYMONTHDAY with WEEKLY' => [
            static fn () => new RecurrenceRule(Frequency::Weekly, byMonthDay: [1]),
            RuleViolation::ByMonthDayNotAllowedWithWeekly,
            'BYMONTHDAY is not allowed with FREQ=WEEKLY',
        ];
        yield 'BYSETPOS alone' => [
            static fn () => new RecurrenceRule(Frequency::Monthly, bySetPos: [1]),
            RuleViolation::BySetPosRequiresAnotherByPart,
            'BYSETPOS must be used together with another BYxxx rule part',
        ];
        yield 'SKIP without RSCALE' => [
            static fn () => new RecurrenceRule(Frequency::Monthly, skip: Skip::Forward),
            RuleViolation::SkipRequiresCalendarScale,
            'SKIP requires RSCALE (RFC 7529 section 4.1)',
        ];
    }

    /**
     * @param callable(): RecurrenceRule $create
     */
    #[DataProvider('provideValidEdgeRules')]
    public function testValidEdges(
        callable $create,
        string $expected,
    ): void
    {
        self::assertSame($expected, $create()->toString());
    }

    /**
     * @return iterable<string, array{callable(): RecurrenceRule, string}>
     */
    public static function provideValidEdgeRules(): iterable
    {
        yield 'BYSECOND 60 and 0' => [static fn () => new RecurrenceRule(Frequency::Minutely, bySecond: [0, 60]), 'FREQ=MINUTELY;BYSECOND=0,60'];
        yield 'BYMINUTE 0 and 59' => [static fn () => new RecurrenceRule(Frequency::Hourly, byMinute: [0, 59]), 'FREQ=HOURLY;BYMINUTE=0,59'];
        yield 'BYHOUR 0 and 23' => [static fn () => new RecurrenceRule(Frequency::Daily, byHour: [0, 23]), 'FREQ=DAILY;BYHOUR=0,23'];
        yield 'BYMONTHDAY extremes' => [static fn () => new RecurrenceRule(Frequency::Monthly, byMonthDay: [-31, 31, 1, -1]), 'FREQ=MONTHLY;BYMONTHDAY=-31,31,1,-1'];
        yield 'BYYEARDAY extremes' => [static fn () => new RecurrenceRule(Frequency::Yearly, byYearDay: [-366, 366]), 'FREQ=YEARLY;BYYEARDAY=-366,366'];
        yield 'BYYEARDAY with HOURLY' => [static fn () => new RecurrenceRule(Frequency::Hourly, byYearDay: [1]), 'FREQ=HOURLY;BYYEARDAY=1'];
        yield 'BYWEEKNO extremes' => [static fn () => new RecurrenceRule(Frequency::Yearly, byWeekNo: [-53, 53]), 'FREQ=YEARLY;BYWEEKNO=-53,53'];
        yield 'BYMONTH extremes' => [static fn () => new RecurrenceRule(Frequency::Yearly, byMonth: [1, 12]), 'FREQ=YEARLY;BYMONTH=1,12'];
        yield 'BYSETPOS extremes' => [static fn () => new RecurrenceRule(Frequency::Yearly, byMonth: [1], bySetPos: [-366, 366]), 'FREQ=YEARLY;BYMONTH=1;BYSETPOS=-366,366'];
        yield 'BYSETPOS with BYSECOND only' => [static fn () => new RecurrenceRule(Frequency::Minutely, bySecond: [1], bySetPos: [1]), 'FREQ=MINUTELY;BYSECOND=1;BYSETPOS=1'];
        yield 'BYDAY ordinal and plain mixed' => [static fn () => new RecurrenceRule(Frequency::Monthly, byDay: [WeekdayNum::every(Weekday::Monday), WeekdayNum::of(Weekday::Friday, 1)]), 'FREQ=MONTHLY;BYDAY=MO,1FR'];
        yield 'BYDAY ordinal with YEARLY' => [static fn () => new RecurrenceRule(Frequency::Yearly, byDay: [WeekdayNum::of(Weekday::Monday, 53)]), 'FREQ=YEARLY;BYDAY=53MO'];
        yield 'RSCALE without SKIP' => [static fn () => new RecurrenceRule(Frequency::Yearly, calendarScale: CalendarScale::Gregorian), 'FREQ=YEARLY;RSCALE=GREGORIAN'];
        yield 'UNTIL instant' => [static fn () => new RecurrenceRule(Frequency::Daily, end: Until::instant(new DateTimeImmutable('2026-01-01T10:00:00+01:00'))), 'FREQ=DAILY;UNTIL=20260101T090000Z'];
    }

    public function testExceptionIsPartOfTheHierarchy(): void
    {
        try {
            new RecurrenceRule(Frequency::Daily, byHour: [24]); // @phpstan-ignore argument.type
        } catch (Throwable $e) {
            self::assertInstanceOf(RecurrenceException::class, $e);
            self::assertInstanceOf(RuntimeException::class, $e);
        }
    }

}
