<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\DtStartPolicy;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\SearchLimitExceededException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Internal\DateMatcher;
use ShipMonk\RRule\Internal\ExpansionPlan;
use ShipMonk\RRule\Internal\LocalRuleIterator;
use ShipMonk\RRule\Internal\RecurrenceEngine;
use ShipMonk\RRule\Internal\RuleStream;
use ShipMonk\RRule\Internal\SearchBudget;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\Internal\Values\DateValues;
use ShipMonk\RRule\Internal\Values\FloatingValues;
use ShipMonk\RRule\Internal\Values\InstantValues;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function intdiv;
use function iterator_to_array;
use function ksort;
use function memory_get_peak_usage;
use function memory_get_usage;
use function range;
use function str_replace;
use const PHP_INT_MAX;

#[CoversClass(Recurrence::class)]
#[CoversClass(RecurrenceEngine::class)]
#[CoversClass(RuleStream::class)]
#[CoversClass(LocalRuleIterator::class)]
#[CoversClass(DateMatcher::class)]
#[CoversClass(ExpansionPlan::class)]
#[CoversClass(SearchBudget::class)]
#[CoversClass(ValueKind::class)]
#[CoversClass(DateValues::class)]
#[CoversClass(FloatingValues::class)]
#[CoversClass(InstantValues::class)]
final class RecurrenceTest extends TestCase
{

    public function testKinds(): void
    {
        $prague = new DateTimeZone('Europe/Prague');
        $instant = Recurrence::of(new DateTimeImmutable('2026-01-01', $prague));
        $kinds = [
            'DTSTART;VALUE=DATE:20260101' => [true, false, false],
            'DTSTART:20260101T000000' => [false, true, false],
            'DTSTART:20260101T000000Z' => [false, false, true],
            'DTSTART;TZID=Europe/Prague:20260101T000000' => [false, false, true],
        ];

        foreach ($kinds as $text => $expected) {
            $recurrence = Recurrence::fromString($text);
            self::assertSame($expected, [$recurrence->isAllDay(), $recurrence->isFloating(), $recurrence->isInstant()], $text);
        }

        foreach (['2026-01-01T00:00:00Z', '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00 UTC'] as $utc) {
            self::assertSame('DTSTART:20260101T000000Z', Recurrence::of(new DateTimeImmutable($utc))->toString(), $utc);
        }

        self::assertSame('DTSTART;TZID=Europe/Prague:20260101T000000', $instant->toString());
    }

    #[DataProvider('provideUnsupportedZones')]
    public function testUnsupportedStartZone(string $dateTime): void
    {
        $this->expectException(InvalidRecurrenceException::class);
        $this->expectExceptionMessageMatches('~^Invalid start: Time zone ".+" is not supported: use a named IANA zone~');

        Recurrence::of(new DateTimeImmutable($dateTime));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedZones(): iterable
    {
        yield 'offset' => ['2026-01-01T00:00:00+02:00'];
        yield 'abbreviation' => ['2026-01-01 00:00:00 CEST'];
    }

    public function testStartIsNormalized(): void
    {
        $start = new DateTimeImmutable('2026-01-01 09:00:00.123456', new DateTimeZone('Europe/Prague'));
        $recurrence = Recurrence::of($start);

        self::assertSame('2026-01-01T09:00:00.000000+01:00', $recurrence->getStart()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('Europe/Prague', $recurrence->getStart()->getTimezone()->getName());
    }

    /**
     * @param callable(): mixed $create
     */
    #[DataProvider('provideRulesThatDoNotFitTheStart')]
    public function testRuleMustFitTheStart(
        callable $create,
        string $message,
    ): void
    {
        $this->expectException(InvalidRecurrenceException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function provideRulesThatDoNotFitTheStart(): iterable
    {
        $date = CalendarDate::of(2026, 1, 1);
        $floating = FloatingDateTime::of(2026, 1, 1, 9);
        $instant = new DateTimeImmutable('2026-01-01T09:00:00Z');

        yield 'time parts with a DATE start' => [
            static fn () => Recurrence::of($date, new RecurrenceRule(Frequency::Daily, byHour: [9])),
            'RRULE FREQ=DAILY;BYHOUR=9: BYSECOND, BYMINUTE and BYHOUR must not be used with a DATE start (RFC 5545 section 3.3.10)',
        ];
        yield 'BYMINUTE with a DATE start' => [
            static fn () => Recurrence::of($date, new RecurrenceRule(Frequency::Daily, byMinute: [9])),
            'RRULE FREQ=DAILY;BYMINUTE=9: BYSECOND, BYMINUTE and BYHOUR must not be used with a DATE start (RFC 5545 section 3.3.10)',
        ];
        yield 'BYSECOND with a DATE start' => [
            static fn () => Recurrence::of($date, new RecurrenceRule(Frequency::Daily, bySecond: [9])),
            'RRULE FREQ=DAILY;BYSECOND=9: BYSECOND, BYMINUTE and BYHOUR must not be used with a DATE start (RFC 5545 section 3.3.10)',
        ];
        yield 'HOURLY with a DATE start' => [
            static fn () => Recurrence::of($date, new RecurrenceRule(Frequency::Hourly)),
            'RRULE FREQ=HOURLY: a DATE start cannot have a frequency shorter than a day',
        ];
        yield 'MINUTELY with a DATE start' => [
            static fn () => Recurrence::of($date, new RecurrenceRule(Frequency::Minutely)),
            'RRULE FREQ=MINUTELY: a DATE start cannot have a frequency shorter than a day',
        ];
        yield 'SECONDLY with a DATE start' => [
            static fn () => Recurrence::of($date, new RecurrenceRule(Frequency::Secondly)),
            'RRULE FREQ=SECONDLY: a DATE start cannot have a frequency shorter than a day',
        ];
        yield 'DATE-TIME UNTIL with a DATE start' => [
            static fn () => Recurrence::of($date, new RecurrenceRule(Frequency::Daily, end: Until::floating($floating))),
            'RRULE FREQ=DAILY;UNTIL=20260101T090000: UNTIL must be a DATE for this start (RFC 5545 section 3.3.10)',
        ];
        yield 'DATE UNTIL with a floating start' => [
            static fn () => Recurrence::of($floating, new RecurrenceRule(Frequency::Daily, end: Until::date($date))),
            'RRULE FREQ=DAILY;UNTIL=20260101: UNTIL must be a floating DATE-TIME for this start (RFC 5545 section 3.3.10)',
        ];
        yield 'floating UNTIL with a zoned start' => [
            static fn () => Recurrence::of(new DateTimeImmutable('2026-01-01', new DateTimeZone('Europe/Prague')), new RecurrenceRule(Frequency::Daily, end: Until::floating($floating))),
            'RRULE FREQ=DAILY;UNTIL=20260101T090000: UNTIL must be a UTC DATE-TIME for this start (RFC 5545 section 3.3.10)',
        ];
        yield 'DATE UNTIL with a UTC start' => [
            static fn () => Recurrence::of($instant, new RecurrenceRule(Frequency::Daily, end: Until::date($date))),
            'RRULE FREQ=DAILY;UNTIL=20260101: UNTIL must be a UTC DATE-TIME for this start (RFC 5545 section 3.3.10)',
        ];
        yield 'withRules' => [
            static fn () => Recurrence::of($date)->withRules(new RecurrenceRule(Frequency::Hourly)),
            'RRULE FREQ=HOURLY: a DATE start cannot have a frequency shorter than a day',
        ];
        yield 'withExclusionRules' => [
            static fn () => Recurrence::of($date)->withExclusionRules(new RecurrenceRule(Frequency::Hourly)),
            'EXRULE FREQ=HOURLY: a DATE start cannot have a frequency shorter than a day',
        ];
    }

    /**
     * @param callable(): mixed $create
     */
    #[DataProvider('provideValuesOfAnotherType')]
    public function testValueOfAnotherTypeIsAnArgumentError(
        callable $create,
        string $message,
    ): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function provideValuesOfAnotherType(): iterable
    {
        $date = CalendarDate::of(2026, 1, 1);
        $floating = FloatingDateTime::of(2026, 1, 1, 9);
        $instant = new DateTimeImmutable('2026-01-01T09:00:00Z');

        yield 'RDATE type' => [
            static fn () => Recurrence::of($date)->withAddedDates($floating), // @phpstan-ignore argument.type
            'The RDATE value must be a CalendarDate, because the start is a date',
        ];
        yield 'EXDATE type' => [
            static fn () => Recurrence::of($floating)->withExcludedDates($date), // @phpstan-ignore argument.type
            'The EXDATE value must be a FloatingDateTime, because the start is a floating date-time',
        ];
        yield 'firstAfter type' => [
            static fn () => Recurrence::of($instant)->firstAfter($floating), // @phpstan-ignore argument.type
            'The after value must be a DateTimeImmutable, because the start is a date-time with a time zone',
        ];
        yield 'lastBefore type' => [
            static fn () => Recurrence::of($instant)->lastBefore($date), // @phpstan-ignore argument.type
            'The before value must be a DateTimeImmutable, because the start is a date-time with a time zone',
        ];
        yield 'between from type' => [
            static fn () => Recurrence::of($date)->between($floating, $date), // @phpstan-ignore argument.type
            'The from value must be a CalendarDate, because the start is a date',
        ];
        yield 'between to type' => [
            static fn () => Recurrence::of($date)->between($date, $floating), // @phpstan-ignore argument.type
            'The to value must be a CalendarDate, because the start is a date',
        ];
        yield 'occursAt type' => [
            static fn () => Recurrence::of($date)->occursAt($floating), // @phpstan-ignore argument.type
            'The value value must be a CalendarDate, because the start is a date',
        ];
    }

    public function testValidUntilTypes(): void
    {
        $date = CalendarDate::of(2026, 1, 1);
        $floating = FloatingDateTime::of(2026, 1, 1, 9);

        self::assertCount(3, Recurrence::of($date, new RecurrenceRule(Frequency::Daily, end: Until::date(CalendarDate::of(2026, 1, 3))))->take(10));
        self::assertCount(3, Recurrence::of($floating, new RecurrenceRule(Frequency::Daily, end: Until::floating(FloatingDateTime::of(2026, 1, 3, 9))))->take(10));
        self::assertCount(3, Recurrence::of(new DateTimeImmutable('2026-01-01 09:00', new DateTimeZone('Europe/Prague')), new RecurrenceRule(Frequency::Daily, end: Until::instant(new DateTimeImmutable('2026-01-03T08:00:00Z'))))->take(10));
    }

    public function testGetters(): void
    {
        $rule = new RecurrenceRule(Frequency::Weekly);
        $exclusionRule = new RecurrenceRule(Frequency::Monthly);
        $start = CalendarDate::of(2026, 1, 1);
        $added = CalendarDate::of(2026, 1, 2);
        $excluded = CalendarDate::of(2026, 1, 8);
        $day = CalendarDate::of(2026, 1, 15);
        $recurrence = Recurrence::of($start, $rule)
            ->withAddedDates($added)
            ->withExcludedDates($excluded)
            ->withExcludedDays($day)
            ->withExclusionRules($exclusionRule)
            ->withDtStartPolicy(DtStartPolicy::OnlyIfMatching);

        self::assertSame($start, $recurrence->getStart());
        self::assertSame([$rule], $recurrence->getRules());
        self::assertSame([$added], $recurrence->getAddedDates());
        self::assertSame([$excluded], $recurrence->getExcludedDates());
        self::assertSame([$day], $recurrence->getExcludedDays());
        self::assertSame(Recurrence::DEFAULT_SEARCH_LIMIT, $recurrence->getSearchLimit());
        self::assertSame(7, $recurrence->withSearchLimit(7)->getSearchLimit());
        self::assertSame([$exclusionRule], $recurrence->getExclusionRules());
        self::assertSame(DtStartPolicy::OnlyIfMatching, $recurrence->getDtStartPolicy());
        self::assertSame(DtStartPolicy::Always, Recurrence::of($start)->getDtStartPolicy());

        $newRule = new RecurrenceRule(Frequency::Daily);
        $replaced = $recurrence->withRules($newRule)->withAddedDates($day)->withExcludedDates($day)->withExcludedDays($added);

        self::assertSame([$newRule], $replaced->getRules());
        self::assertSame([$day], $replaced->getAddedDates(), 'every with* method replaces its list');
        self::assertSame([$day], $replaced->getExcludedDates());
        self::assertSame([$added], $replaced->getExcludedDays());
        self::assertSame([], $replaced->withAddedDates()->withExcludedDates()->withExcludedDays()->getAddedDates());

        $floating = Recurrence::of(FloatingDateTime::of(2026, 1, 1))->withExcludedDays($day);
        self::assertSame([$day], $floating->getExcludedDays());
        self::assertSame([], $floating->getExcludedDates());
        self::assertSame([$exclusionRule], $replaced->getExclusionRules());
        self::assertSame(DtStartPolicy::OnlyIfMatching, $replaced->getDtStartPolicy());
        self::assertSame([$rule], $recurrence->getRules(), 'the original is unchanged');
    }

    public function testInstantsAreNormalizedToTheStartZone(): void
    {
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-01-01 09:00', new DateTimeZone('Europe/Prague')))
            ->withAddedDates(new DateTimeImmutable('2026-01-05T08:00:00.5Z'))
            ->withExcludedDates(new DateTimeImmutable('2026-01-01 03:00:00', new DateTimeZone('America/New_York')));

        self::assertSame(['2026-01-05T09:00:00.000000+01:00'], array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d\TH:i:s.uP'), $recurrence->getAddedDates()));
        self::assertSame(['2026-01-05T09:00:00+01:00'], self::formatAll($recurrence));
    }

    public function testIterationKeysAndReiteration(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 30), new RecurrenceRule(Frequency::Daily, end: Count::of(3)));

        self::assertSame([0, 1, 2], array_keys(iterator_to_array($recurrence, true)));
        self::assertSame(['2026-01-30', '2026-01-31', '2026-02-01'], self::formatAll($recurrence));
        self::assertSame(['2026-01-30', '2026-01-31', '2026-02-01'], self::formatAll($recurrence));
    }

    public function testQueries(): void
    {
        $prague = new DateTimeZone('Europe/Prague');
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-01-05 09:00', $prague), new RecurrenceRule(Frequency::Weekly, byDay: [WeekdayNum::every(Weekday::Monday), WeekdayNum::every(Weekday::Thursday)]));
        $at = static fn (string $local): DateTimeImmutable => new DateTimeImmutable($local, $prague);

        self::assertSame('2026-01-05T09:00:00+01:00', $recurrence->first()?->format('c'));
        self::assertSame('2026-01-08T09:00:00+01:00', $recurrence->firstAfter($at('2026-01-05 09:00'))?->format('c'));
        self::assertSame('2026-01-05T09:00:00+01:00', $recurrence->firstAfter($at('2026-01-05 09:00'), inclusive: true)?->format('c'));
        self::assertSame('2026-01-05T09:00:00+01:00', $recurrence->firstAfter($at('2025-01-01'))?->format('c'));
        self::assertSame('2026-11-05T09:00:00+01:00', $recurrence->firstAfter(new DateTimeImmutable('2026-11-03T12:00:00Z'))?->format('c'));
        self::assertSame('2026-11-02T09:00:00+01:00', $recurrence->lastBefore($at('2026-11-05 09:00'))?->format('c'));
        self::assertSame('2026-11-05T09:00:00+01:00', $recurrence->lastBefore($at('2026-11-05 09:00'), inclusive: true)?->format('c'));
        self::assertNull($recurrence->lastBefore($at('2026-01-05 09:00')));
        self::assertSame('2026-01-05T09:00:00+01:00', $recurrence->lastBefore($at('2026-01-05 09:00'), inclusive: true)?->format('c'));
        self::assertSame('2026-01-05T09:00:00+01:00', $recurrence->lastBefore($at('2026-01-07 09:00'))?->format('c'));
        self::assertSame(['2026-03-02T09:00:00+01:00', '2026-03-05T09:00:00+01:00'], self::formatAll($recurrence->between($at('2026-03-02 09:00'), $at('2026-03-09 09:00'))));
        self::assertSame([], self::formatAll($recurrence->between($at('2026-03-03'), $at('2026-03-05 09:00'))));
        self::assertTrue($recurrence->occursAt($at('2026-03-05 09:00')));
        self::assertFalse($recurrence->occursAt($at('2026-03-05 09:00:01')));
        self::assertFalse($recurrence->occursAt($at('2026-03-04 09:00')));
        self::assertSame([], $recurrence->take(0));
        self::assertSame(['2026-01-05T09:00:00+01:00', '2026-01-08T09:00:00+01:00'], self::formatAll($recurrence->take(2)));
        self::assertFalse($recurrence->isFinite());
    }

    public function testQueriesOfACountedRuleStartFromTheBeginning(): void
    {
        $recurrence = Recurrence::of(FloatingDateTime::of(2026, 1, 1, 9), new RecurrenceRule(Frequency::Monthly, end: Count::of(12)));

        self::assertTrue($recurrence->isFinite());
        self::assertSame('2026-06-01T09:00:00', $recurrence->firstAfter(FloatingDateTime::of(2026, 5, 15))?->toString());
        self::assertNull($recurrence->firstAfter(FloatingDateTime::of(2026, 12, 1, 9)));
        self::assertSame('2026-12-01T09:00:00', $recurrence->lastBefore(FloatingDateTime::of(2030, 1, 1))?->toString());
        self::assertSame('2026-05-01T09:00:00', $recurrence->lastBefore(FloatingDateTime::of(2026, 5, 15))?->toString());
        self::assertSame(['2026-11-01T09:00:00', '2026-12-01T09:00:00'], self::formatAll($recurrence->between(FloatingDateTime::of(2026, 11, 1), FloatingDateTime::of(2027, 1, 1))));
    }

    public function testLastBeforeWidensTheWindow(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(2000, 2, 29), new RecurrenceRule(Frequency::Yearly, interval: 4, byMonth: [2], byMonthDay: [29], byDay: [WeekdayNum::every(Weekday::Tuesday)]));

        self::assertSame('2000-02-29', $recurrence->lastBefore(CalendarDate::of(2020, 1, 1))?->toString());
        self::assertSame('2028-02-29', $recurrence->lastBefore(CalendarDate::of(2040, 1, 1))?->toString());
        self::assertNull($recurrence->lastBefore(CalendarDate::of(1999, 1, 1)));
    }

    public function testRecurrenceSet(): void
    {
        $recurrence = Recurrence::of(
            CalendarDate::of(2026, 1, 1),
            new RecurrenceRule(Frequency::Weekly, end: Count::of(4)),
            new RecurrenceRule(Frequency::Monthly, end: Count::of(3)),
        )
            ->withAddedDates(CalendarDate::of(2026, 1, 3), CalendarDate::of(2026, 1, 1), CalendarDate::of(2026, 1, 3))
            ->withExcludedDates(CalendarDate::of(2026, 1, 8))
            ->withExclusionRules(new RecurrenceRule(Frequency::Monthly, byMonthDay: [22]));

        self::assertSame(['2026-01-01', '2026-01-03', '2026-01-15', '2026-02-01', '2026-03-01'], self::formatAll($recurrence));
    }

    public function testExcludedDaysUseTheLocalDate(): void
    {
        $newYork = new DateTimeZone('America/New_York');
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-01-01 21:00', $newYork), new RecurrenceRule(Frequency::Daily, byHour: [9, 21], end: Count::of(5)))
            ->withExcludedDays(CalendarDate::of(2026, 1, 2));

        // 2026-01-02 21:00 in New York is 2026-01-03 in UTC; it is still excluded
        self::assertSame(['2026-01-01T21:00:00-05:00', '2026-01-03T09:00:00-05:00', '2026-01-03T21:00:00-05:00'], self::formatAll($recurrence));

        $floating = Recurrence::of(FloatingDateTime::of(2026, 1, 1, 9), new RecurrenceRule(Frequency::Daily, end: Count::of(3)))
            ->withExcludedDays(CalendarDate::of(2026, 1, 2));
        self::assertSame(['2026-01-01T09:00:00', '2026-01-03T09:00:00'], self::formatAll($floating));
    }

    public function testDtStartPolicy(): void
    {
        $rule = new RecurrenceRule(Frequency::Monthly, byMonthDay: [15], end: Count::of(2));
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 1), $rule);

        self::assertSame(['2026-01-01', '2026-01-15'], self::formatAll($recurrence), 'the start counts for COUNT');
        self::assertSame(['2026-01-15', '2026-02-15'], self::formatAll($recurrence->withDtStartPolicy(DtStartPolicy::OnlyIfMatching)));
        self::assertSame(['2026-01-01'], self::formatAll(Recurrence::of(CalendarDate::of(2026, 1, 1))->withDtStartPolicy(DtStartPolicy::OnlyIfMatching)), 'no rules: the start is the only occurrence');
        self::assertSame([], self::formatAll(Recurrence::of(CalendarDate::of(2026, 1, 1))->withExcludedDates(CalendarDate::of(2026, 1, 1))));
        self::assertNull(Recurrence::of(CalendarDate::of(2026, 1, 1))->withExcludedDates(CalendarDate::of(2026, 1, 1))->first());
    }

    public function testUntilBeforeStart(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 10), new RecurrenceRule(Frequency::Daily, end: Until::date(CalendarDate::of(2026, 1, 1))));

        self::assertSame(['2026-01-10'], self::formatAll($recurrence));
        self::assertSame([], self::formatAll($recurrence->withDtStartPolicy(DtStartPolicy::OnlyIfMatching)));
    }

    public function testEndOfSupportedRange(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(9999, 12, 30), new RecurrenceRule(Frequency::Daily));

        self::assertSame(['9999-12-30', '9999-12-31'], self::formatAll($recurrence));
        self::assertSame(['9999-12-31T23:59:58', '9999-12-31T23:59:59'], self::formatAll(Recurrence::of(FloatingDateTime::of(9999, 12, 31, 23, 59, 58), new RecurrenceRule(Frequency::Secondly))));
        self::assertSame(['9998-01-01'], self::formatAll(Recurrence::of(CalendarDate::of(9998, 1, 1), new RecurrenceRule(Frequency::Yearly, interval: 2))));
        self::assertSame(['2026-01-01'], self::formatAll(Recurrence::of(CalendarDate::of(2026, 1, 1), new RecurrenceRule(Frequency::Daily, interval: PHP_INT_MAX))));
        self::assertSame(['2026-01-01T00:00:00'], self::formatAll(Recurrence::of(FloatingDateTime::of(2026, 1, 1), new RecurrenceRule(Frequency::Hourly, interval: PHP_INT_MAX))));
    }

    public function testNeverMatchingRulesEnd(): void
    {
        $never = [
            new RecurrenceRule(Frequency::Yearly, byMonth: [2], byMonthDay: [30]),
            new RecurrenceRule(Frequency::Monthly, byMonthDay: [31], byMonth: [4, 6, 9, 11]),
            new RecurrenceRule(Frequency::Daily, interval: 7, byDay: [WeekdayNum::every(Weekday::Tuesday)]),
            new RecurrenceRule(Frequency::Hourly, interval: 2, byHour: [1]),
            new RecurrenceRule(Frequency::Minutely, bySetPos: [2], bySecond: [5]),
            new RecurrenceRule(Frequency::Daily, bySecond: [60]),
            new RecurrenceRule(Frequency::Yearly, byWeekNo: [53], byMonth: [6]),
        ];

        foreach ($never as $rule) {
            self::assertSame(['2026-01-05T00:00:00'], self::formatAll(Recurrence::of(FloatingDateTime::of(2026, 1, 5), $rule)), $rule->toString());
        }
    }

    public function testRulesThatCanNeverAlignNeedNoIteration(): void
    {
        $never = [
            new RecurrenceRule(Frequency::Daily, interval: 7, byDay: [WeekdayNum::every(Weekday::Tuesday)]),
            new RecurrenceRule(Frequency::Hourly, interval: 2, byHour: [1]),
            new RecurrenceRule(Frequency::Minutely, interval: 2, byMinute: [1]),
            new RecurrenceRule(Frequency::Secondly, interval: 2, bySecond: [1]),
            new RecurrenceRule(Frequency::Minutely, bySetPos: [2], bySecond: [5]),
            new RecurrenceRule(Frequency::Daily, bySecond: [60]),
        ];

        foreach ($never as $rule) {
            $recurrence = Recurrence::of(FloatingDateTime::of(2026, 1, 5), $rule)->withSearchLimit(2);
            self::assertSame(['2026-01-05T00:00:00'], self::formatAll($recurrence), $rule->toString());
        }

        $aligned = Recurrence::of(FloatingDateTime::of(2026, 1, 5), new RecurrenceRule(Frequency::Daily, interval: 7, byDay: [WeekdayNum::every(Weekday::Monday)], end: Count::of(2)));
        self::assertSame(['2026-01-05T00:00:00', '2026-01-12T00:00:00'], self::formatAll($aligned));

        $hugeInterval = Recurrence::of(FloatingDateTime::of(2026, 1, 5), new RecurrenceRule(Frequency::Secondly, interval: PHP_INT_MAX, bySecond: [1]));
        self::assertSame(['2026-01-05T00:00:00'], self::formatAll($hugeInterval));
    }

    public function testInTimeZoneKeepsTheWrittenTime(): void
    {
        $newYork = new DateTimeZone('America/New_York');
        $recurrence = Recurrence::inTimeZone(FloatingDateTime::of(2026, 3, 8, 2), $newYork, new RecurrenceRule(Frequency::Weekly, end: Count::of(3)));

        self::assertSame(['2026-03-08T03:00:00-04:00', '2026-03-15T02:00:00-04:00', '2026-03-22T02:00:00-04:00'], self::formatAll($recurrence));
        self::assertSame('2026-03-08T03:00:00-04:00', $recurrence->getStart()->format('c'));
        self::assertSame('2026-03-08T02:00:00', $recurrence->getStartLocalTime()->toString());
        self::assertSame("DTSTART;TZID=America/New_York:20260308T020000\nRRULE:FREQ=WEEKLY;COUNT=3", $recurrence->toString("\n"));
        self::assertSame(['2026-03-08T03:00:00-04:00', '2026-03-15T02:00:00-04:00'], self::formatAll(Recurrence::fromString($recurrence->toString())->take(2)));

        // a start given as an instant keeps its wall-clock time
        $instant = Recurrence::of(new DateTimeImmutable('2026-03-08 02:00', $newYork), new RecurrenceRule(Frequency::Weekly, end: Count::of(2)));
        self::assertSame(['2026-03-08T03:00:00-04:00', '2026-03-15T03:00:00-04:00'], self::formatAll($instant));

        $this->expectException(InvalidRecurrenceException::class);
        Recurrence::inTimeZone(FloatingDateTime::of(2026, 3, 8, 2), new DateTimeZone('+02:00'));
    }

    public function testSearchLimit(): void
    {
        // February 29 on a Monday: 28 years apart, found in one jump
        $leapMonday = Recurrence::of(CalendarDate::of(2016, 2, 29), new RecurrenceRule(Frequency::Daily, byMonth: [2], byMonthDay: [29], byDay: [WeekdayNum::every(Weekday::Monday)]));
        self::assertSame('2044-02-29', $leapMonday->withSearchLimit(2)->firstAfter(CalendarDate::of(2016, 3, 1))?->toString());

        // BYSETPOS rejects each month from December 2026 to February 2027, so the answer is in the fourth period
        $fifthMonday = Recurrence::of(CalendarDate::of(2026, 1, 1), new RecurrenceRule(Frequency::Monthly, byDay: [WeekdayNum::every(Weekday::Monday)], bySetPos: [5]));
        self::assertSame('2027-03-29', $fifthMonday->withSearchLimit(4)->firstAfter(CalendarDate::of(2026, 12, 1))?->toString());

        $this->expectException(SearchLimitExceededException::class);
        $this->expectExceptionMessage('The search examined 3 periods without reaching an answer; raise the limit with Recurrence::withSearchLimit()');

        $fifthMonday->withSearchLimit(3)->firstAfter(CalendarDate::of(2026, 12, 1));
    }

    public function testInvalidSearchLimit(): void
    {
        $this->expectException(LogicException::class);

        Recurrence::of(CalendarDate::of(2016, 2, 29))->withSearchLimit(0); // @phpstan-ignore argument.type
    }

    /**
     * @param list<string> $expected ISO 8601 with offset
     */
    #[DataProvider('provideDaylightSavingTime')]
    public function testDaylightSavingTime(
        string $start,
        RecurrenceRule $rule,
        array $expected,
    ): void
    {
        $recurrence = Recurrence::of(new DateTimeImmutable($start, new DateTimeZone('Europe/Prague')), $rule);

        self::assertSame($expected, self::formatAll($recurrence->take(count($expected) + 1)));
    }

    /**
     * @return iterable<string, array{string, RecurrenceRule, list<string>}>
     */
    public static function provideDaylightSavingTime(): iterable
    {
        yield 'S3: a gap time moves forward once, later days keep 02:30' => [
            '2026-03-28 02:30',
            new RecurrenceRule(Frequency::Daily, end: Count::of(4)),
            ['2026-03-28T02:30:00+01:00', '2026-03-29T03:30:00+02:00', '2026-03-30T02:30:00+02:00', '2026-03-31T02:30:00+02:00'],
        ];
        yield 'S4: an overlap time is the first occurrence' => [
            '2026-10-24 02:30',
            new RecurrenceRule(Frequency::Daily, end: Count::of(3)),
            ['2026-10-24T02:30:00+02:00', '2026-10-25T02:30:00+02:00', '2026-10-26T02:30:00+01:00'],
        ];
        yield 'S5: HOURLY across the gap counts distinct instants' => [
            '2026-03-29 00:00',
            new RecurrenceRule(Frequency::Hourly, end: Count::of(5)),
            ['2026-03-29T00:00:00+01:00', '2026-03-29T01:00:00+01:00', '2026-03-29T03:00:00+02:00', '2026-03-29T04:00:00+02:00', '2026-03-29T05:00:00+02:00'],
        ];
        yield 'S5: HOURLY with two minutes across the gap stays in order' => [
            '2026-03-29 01:00',
            new RecurrenceRule(Frequency::Hourly, byMinute: [0, 30], end: Count::of(6)),
            ['2026-03-29T01:00:00+01:00', '2026-03-29T01:30:00+01:00', '2026-03-29T03:00:00+02:00', '2026-03-29T03:30:00+02:00', '2026-03-29T04:00:00+02:00', '2026-03-29T04:30:00+02:00'],
        ];
        yield 'S5: HOURLY across the overlap steps in wall-clock time' => [
            '2026-10-25 00:00',
            new RecurrenceRule(Frequency::Hourly, end: Count::of(5)),
            ['2026-10-25T00:00:00+02:00', '2026-10-25T01:00:00+02:00', '2026-10-25T02:00:00+02:00', '2026-10-25T03:00:00+01:00', '2026-10-25T04:00:00+01:00'],
        ];
        yield 'UNTIL in UTC bounds a zoned MINUTELY rule' => [
            '2026-07-01 09:58',
            new RecurrenceRule(Frequency::Minutely, end: Until::instant(new DateTimeImmutable('2026-07-01T08:00:00Z'))),
            ['2026-07-01T09:58:00+02:00', '2026-07-01T09:59:00+02:00', '2026-07-01T10:00:00+02:00'],
        ];
        yield 'UNTIL in UTC bounds a zoned DAILY rule' => [
            '2026-07-01 09:00',
            new RecurrenceRule(Frequency::Daily, end: Until::instant(new DateTimeImmutable('2026-07-02T06:30:00Z'))),
            ['2026-07-01T09:00:00+02:00'],
        ];
    }

    #[DataProvider('provideLastBeforeFrequencies')]
    public function testLastBeforeForEveryFrequency(
        Frequency $frequency,
        string $expected,
    ): void
    {
        $recurrence = Recurrence::of(FloatingDateTime::of(2026, 1, 1, 0, 0, 10), new RecurrenceRule($frequency, interval: 3));

        self::assertSame($expected, $recurrence->lastBefore(FloatingDateTime::of(2026, 7, 1, 12, 0, 5))?->toString());
    }

    /**
     * @return iterable<string, array{Frequency, string}>
     */
    public static function provideLastBeforeFrequencies(): iterable
    {
        yield 'yearly' => [Frequency::Yearly, '2026-01-01T00:00:10'];
        yield 'monthly' => [Frequency::Monthly, '2026-07-01T00:00:10'];
        yield 'weekly' => [Frequency::Weekly, '2026-06-18T00:00:10'];
        yield 'daily' => [Frequency::Daily, '2026-06-30T00:00:10'];
        yield 'hourly' => [Frequency::Hourly, '2026-07-01T09:00:10'];
        yield 'minutely' => [Frequency::Minutely, '2026-07-01T11:57:10'];
        yield 'secondly' => [Frequency::Secondly, '2026-07-01T12:00:04'];
    }

    public function testLastBeforeInclusiveIsExact(): void
    {
        $recurrence = Recurrence::of(FloatingDateTime::of(2026, 1, 1), new RecurrenceRule(Frequency::Secondly, interval: 2));

        self::assertSame('2026-01-01T00:00:10', $recurrence->lastBefore(FloatingDateTime::of(2026, 1, 1, 0, 0, 11), inclusive: true)?->toString());
        self::assertSame('2026-01-01T00:00:10', $recurrence->lastBefore(FloatingDateTime::of(2026, 1, 1, 0, 0, 10), inclusive: true)?->toString());
        self::assertSame('2026-01-01T00:00:08', $recurrence->lastBefore(FloatingDateTime::of(2026, 1, 1, 0, 0, 10))?->toString());
    }

    public function testInstantsInsideADstGapAreReordered(): void
    {
        // Every 7 minutes in wall-clock time: local times in the gap resolve to instants after the local times
        // that follow the gap, so the stream must reorder them.
        $prague = new DateTimeZone('Europe/Prague');
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-03-29 01:00', $prague), new RecurrenceRule(Frequency::Minutely, interval: 7));
        $expected = [];

        for ($minutes = 0; $minutes <= 180; $minutes += 7) {
            $local = FloatingDateTime::of(2026, 3, 29, 1 + intdiv($minutes, 60), $minutes % 60);
            $expected[$local->atTimeZone($prague)->getTimestamp()] = true;
        }

        ksort($expected);
        $actual = [];

        foreach ($recurrence->between(new DateTimeImmutable('2026-03-29 01:00', $prague), new DateTimeImmutable('2026-03-29 03:30', $prague)) as $occurrence) {
            $actual[] = $occurrence->getTimestamp();
        }

        self::assertSame(array_values(array_filter(array_keys($expected), static fn (int $unix): bool => $unix < (new DateTimeImmutable('2026-03-29 03:30', $prague))->getTimestamp())), $actual);
        self::assertCount(17, $actual);
    }

    public function testHugeIntervalStillMatchesItsFirstPeriod(): void
    {
        $secondly = Recurrence::of(FloatingDateTime::of(2026, 1, 5, 0, 0, 1), new RecurrenceRule(Frequency::Secondly, interval: PHP_INT_MAX, bySecond: [1]))
            ->withDtStartPolicy(DtStartPolicy::OnlyIfMatching);
        $hourly = Recurrence::of(FloatingDateTime::of(2026, 1, 5), new RecurrenceRule(Frequency::Hourly, interval: PHP_INT_MAX, byMinute: [30]))
            ->withDtStartPolicy(DtStartPolicy::OnlyIfMatching);

        self::assertSame(['2026-01-05T00:00:01'], self::formatAll($secondly));
        self::assertSame(['2026-01-05T00:30:00'], self::formatAll($hourly));
    }

    public function testQueryBoundsAreExact(): void
    {
        $everySecond = Recurrence::of(FloatingDateTime::of(2026, 1, 1), new RecurrenceRule(Frequency::Secondly));
        $everyOtherSecond = Recurrence::of(FloatingDateTime::of(2026, 1, 1), new RecurrenceRule(Frequency::Secondly, interval: 2));
        $at = FloatingDateTime::of(2026, 1, 1, 0, 0, 10);

        self::assertSame('2026-01-01T00:00:11', $everySecond->firstAfter($at)?->toString());
        self::assertSame('2026-01-01T00:00:10', $everySecond->firstAfter($at, inclusive: true)?->toString());
        self::assertSame('2026-01-01T00:00:09', $everySecond->lastBefore($at)?->toString());
        self::assertSame('2026-01-01T00:00:10', $everySecond->lastBefore($at, inclusive: true)?->toString());
        self::assertSame(['2026-01-01T00:00:10', '2026-01-01T00:00:11'], self::formatAll($everySecond->between($at, FloatingDateTime::of(2026, 1, 1, 0, 0, 12))));
        self::assertTrue($everyOtherSecond->occursAt($at));
        self::assertFalse($everyOtherSecond->occursAt(FloatingDateTime::of(2026, 1, 1, 0, 0, 11)));
        self::assertSame(['2026-01-01T00:00:00'], self::formatAll($everySecond->take(1)));
        self::assertSame('2026-01-01T00:00:00', $everySecond->withSearchLimit(1)->first()?->toString());
    }

    public function testBetweenDoesNotSearchPastItsEnd(): void
    {
        // April and May 2026 have no fifth Monday; the next one is on June 29
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 1), new RecurrenceRule(Frequency::Monthly, byDay: [WeekdayNum::every(Weekday::Monday)], bySetPos: [5]))
            ->withSearchLimit(2);

        self::assertSame([], self::formatAll($recurrence->between(CalendarDate::of(2026, 4, 1), CalendarDate::of(2026, 4, 2))));
    }

    public function testNamedRuleArgumentsBecomeLists(): void
    {
        $rule = new RecurrenceRule(Frequency::Daily);
        $start = FloatingDateTime::of(2026, 1, 1, 9);
        $prague = new DateTimeZone('Europe/Prague');
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 1));

        self::assertSame([$rule], Recurrence::of(CalendarDate::of(2026, 1, 1), ...['daily' => $rule])->getRules());
        self::assertSame([$rule], Recurrence::of($start, ...['daily' => $rule])->getRules());
        self::assertSame([$rule], Recurrence::of($start->atTimeZone($prague), ...['daily' => $rule])->getRules());
        self::assertSame([$rule], Recurrence::inTimeZone($start, $prague, ...['daily' => $rule])->getRules());
        self::assertSame([$rule], $recurrence->withRules(...['daily' => $rule])->getRules());
        self::assertSame([$rule], $recurrence->withExclusionRules(...['daily' => $rule])->getExclusionRules());
        $day = CalendarDate::of(2026, 1, 2);
        self::assertSame([$day], $recurrence->withExcludedDays(...['day' => $day])->getExcludedDays());
    }

    public function testSkipMovesDatesIntoTheNeighbouringPeriod(): void
    {
        $forward = Recurrence::fromString("DTSTART;VALUE=DATE:20260131\nRRULE:RSCALE=GREGORIAN;FREQ=MONTHLY;SKIP=FORWARD");
        self::assertTrue($forward->isAllDay());

        self::assertSame(['2026-01-31', '2026-03-01', '2026-03-31', '2026-05-01', '2026-05-31', '2026-07-01'], self::formatAll($forward->take(6)));
        self::assertSame(['2026-03-01', '2026-03-31'], self::formatAll($forward->between(CalendarDate::of(2026, 3, 1), CalendarDate::of(2026, 4, 1))));
        self::assertTrue($forward->occursAt(CalendarDate::of(2026, 5, 1)));
        self::assertSame('2026-05-01', $forward->firstAfter(CalendarDate::of(2026, 5, 1), inclusive: true)?->toString());
        self::assertSame('2026-05-01', $forward->firstAfter(CalendarDate::of(2026, 4, 30))?->toString());

        $backward = Recurrence::fromString("DTSTART:20260301T090000\nRRULE:RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=-31;SKIP=BACKWARD");
        self::assertTrue($backward->isFloating());
        $untilEndOfMarch = $backward->withRules(RecurrenceRule::fromString('RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=-31;SKIP=BACKWARD;UNTIL=20260331T235959'));

        self::assertSame(['2026-03-01T09:00:00', '2026-03-31T09:00:00'], self::formatAll($untilEndOfMarch));
        self::assertTrue($backward->occursAt(FloatingDateTime::of(2026, 3, 31, 9)));
        self::assertSame('2026-03-31T09:00:00', $backward->lastBefore(FloatingDateTime::of(2026, 3, 31, 10))?->toString());
        self::assertSame(['2026-03-31T09:00:00'], self::formatAll($backward->between(FloatingDateTime::of(2026, 3, 31), FloatingDateTime::of(2026, 3, 31, 23))));

        // the April period selects 05-01T17:00 (moved from April 31), the May period selects 05-01T09:00
        $setPositions = Recurrence::fromString("DTSTART:20260401T090000\nRRULE:RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=1,31;SKIP=FORWARD;BYHOUR=9,17;BYSETPOS=1,-1");

        self::assertSame(
            ['2026-04-01T09:00:00', '2026-05-01T09:00:00', '2026-05-01T17:00:00', '2026-05-31T17:00:00', '2026-06-01T09:00:00', '2026-07-01T09:00:00', '2026-07-01T17:00:00'],
            self::formatAll($setPositions->take(7)),
        );

        // the April period selects 03-31T09:00 (moved from April "day 0"), after the March period selected 03-31T17:00
        $backwardSetPositions = Recurrence::fromString("DTSTART:20260301T090000\nRRULE:RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=-31,31;SKIP=BACKWARD;BYHOUR=9,17;BYSETPOS=1,-1");

        self::assertSame(
            ['2026-03-01T09:00:00', '2026-03-31T09:00:00', '2026-03-31T17:00:00', '2026-04-30T17:00:00', '2026-05-01T09:00:00', '2026-05-31T09:00:00', '2026-05-31T17:00:00'],
            self::formatAll($backwardSetPositions->take(7)),
        );
    }

    public function testQueryValuesWithMicroseconds(): void
    {
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-01-01T10:00:00Z'), new RecurrenceRule(Frequency::Daily));
        $at = new DateTimeImmutable('2026-01-02T10:00:00.5Z');

        self::assertSame('2026-01-03T10:00:00+00:00', $recurrence->firstAfter($at)?->format('c'));
        self::assertSame('2026-01-03T10:00:00+00:00', $recurrence->firstAfter($at, inclusive: true)?->format('c'));
        self::assertSame('2026-01-02T10:00:00+00:00', $recurrence->lastBefore($at)?->format('c'));
        self::assertSame('2026-01-02T10:00:00+00:00', $recurrence->lastBefore($at, inclusive: true)?->format('c'));
        self::assertSame(['2026-01-03T10:00:00+00:00'], self::formatAll($recurrence->between($at, new DateTimeImmutable('2026-01-03T10:00:00.5Z'))));
        self::assertFalse($recurrence->occursAt($at));
        self::assertTrue($recurrence->occursAt(new DateTimeImmutable('2026-01-02T10:00:00Z')));

        $everySecond = Recurrence::of(new DateTimeImmutable('2026-01-01T10:00:00Z'), new RecurrenceRule(Frequency::Secondly));
        $at = new DateTimeImmutable('2026-01-01T10:00:05.5Z');

        self::assertSame('2026-01-01T10:00:06+00:00', $everySecond->firstAfter($at)?->format('c'));
        self::assertSame('2026-01-01T10:00:06+00:00', $everySecond->firstAfter($at, inclusive: true)?->format('c'));
        self::assertSame('2026-01-01T10:00:05+00:00', $everySecond->lastBefore($at)?->format('c'));
        self::assertSame('2026-01-01T10:00:05+00:00', $everySecond->lastBefore($at, inclusive: true)?->format('c'));
        self::assertSame(['2026-01-01T10:00:06+00:00', '2026-01-01T10:00:07+00:00'], self::formatAll($everySecond->between($at, new DateTimeImmutable('2026-01-01T10:00:07.5Z'))));
    }

    public function testValuesOutsideTheYearRangeAreRejected(): void
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-01-01T00:00:00Z'));

        self::assertSame('DTSTART:00010101T000000Z', Recurrence::of(new DateTimeImmutable('@-62135596800'))->toString(), 'the first second of year 1');
        self::assertSame('DTSTART:99991231T235959Z', Recurrence::of(new DateTimeImmutable('@253402300799'))->toString(), 'the last second of year 9999');

        foreach ([
            'start in year 10000' => static fn () => Recurrence::of(new DateTimeImmutable('@253402300800')),
            'start in local year 10000' => static fn () => Recurrence::of((new DateTimeImmutable('@253402290000'))->setTimezone($tokyo)),
            'start in UTC year 0' => static fn () => Recurrence::inTimeZone(FloatingDateTime::of(1, 1, 1), $tokyo),
            'RDATE in year 10000' => static fn () => $recurrence->withAddedDates(new DateTimeImmutable('@253402300800')),
            'EXDATE in year 0' => static fn () => $recurrence->withExcludedDates(new DateTimeImmutable('@-62198755200')),
        ] as $case => $create) {
            try {
                $create();
                self::fail("$case was accepted");
            } catch (InvalidRecurrenceException $e) {
                self::assertStringContainsString('is outside the years 1 to 9999', $e->getMessage(), $case);
            }
        }
    }

    public function testInvalidArguments(): void
    {
        foreach ([
            'take(-1)' => static fn () => Recurrence::of(CalendarDate::of(2026, 1, 1))->take(-1), // @phpstan-ignore argument.type
            'withSearchLimit(0)' => static fn () => Recurrence::of(CalendarDate::of(2026, 1, 1))->withSearchLimit(0), // @phpstan-ignore argument.type
        ] as $case => $call) {
            try {
                $call();
                self::fail("$case was accepted");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('must', $e->getMessage(), $case);
            }
        }
    }

    public function testZonesThatPhpTreatsAsFixedOffsets(): void
    {
        $parsed = Recurrence::fromString("DTSTART;TZID=CET:20260115T120000\nRRULE:FREQ=MONTHLY;BYMONTH=1,7;COUNT=2");
        self::assertTrue($parsed->isInstant());

        self::assertSame(['2026-01-15T12:00:00+01:00', '2026-07-15T12:00:00+02:00'], self::formatAll($parsed));
        self::assertSame('Europe/Brussels', $parsed->getStart()->getTimezone()->getName());

        $this->expectException(InvalidRecurrenceException::class);
        $this->expectExceptionMessage('Invalid start: Time zone "CET" is not supported: PHP treats it as a fixed UTC offset without DST, but the IANA zone has DST; use Europe/Brussels');
        Recurrence::of(new DateTimeImmutable('2026-01-15 12:00', new DateTimeZone('CET')));
    }

    public function testDenseRulesNeedNoMemoryPerCandidate(): void
    {
        $hours = implode(',', range(0, 23));
        $minutes = implode(',', range(0, 59));
        $recurrence = Recurrence::fromString("DTSTART:20260101T000000\nRRULE:FREQ=YEARLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYHOUR=$hours;BYMINUTE=$minutes;BYSECOND=$minutes;BYSETPOS=-1,1");
        self::assertTrue($recurrence->isFloating());
        $memory = memory_get_usage();

        self::assertSame('2026-12-31T23:59:59', $recurrence->firstAfter(FloatingDateTime::of(2026, 6, 1))?->toString());
        self::assertSame(['2026-01-01T00:00:00', '2026-12-31T23:59:59', '2027-01-01T00:00:00'], self::formatAll($recurrence->take(3)));
        self::assertLessThan(50_000_000, memory_get_peak_usage() - $memory);
    }

    public function testSecondlyRuleAcrossADayLongGap(): void
    {
        // Apia skipped 2011-12-30: every local time of that day is in the gap
        $recurrence = Recurrence::fromString("DTSTART;TZID=Pacific/Apia:20111229T235959\nRRULE:FREQ=SECONDLY;COUNT=3");

        self::assertSame(['2011-12-29T23:59:59-10:00', '2011-12-31T00:00:00+14:00', '2011-12-31T00:00:01+14:00'], self::formatAll($recurrence));
    }

    public function testDenseExclusionRuleSkipsAhead(): void
    {
        $recurrence = (new RecurrenceParser(ParseMode::Lenient))->parseRecurrence("DTSTART:20260101T090000Z\nRRULE:FREQ=YEARLY\nEXRULE:FREQ=SECONDLY;BYHOUR=3")->value;

        self::assertSame(['2026-01-01T09:00:00+00:00', '2027-01-01T09:00:00+00:00', '2028-01-01T09:00:00+00:00'], self::formatAll($recurrence->withSearchLimit(1000)->take(3)));
    }

    public function testQueriesBeyondTheSupportedRange(): void
    {
        $daily = Recurrence::fromString("DTSTART:20260101T000000\nRRULE:FREQ=DAILY");
        $yearly = Recurrence::fromString("DTSTART:20260101T000000\nRRULE:FREQ=YEARLY;INTERVAL=999999999999");
        self::assertTrue($daily->isFloating());
        self::assertTrue($yearly->isFloating());

        self::assertSame('9999-12-31T00:00:00', $daily->lastBefore(FloatingDateTime::of(9999, 12, 31, 23, 59, 59))?->toString());
        self::assertSame('2026-01-01T00:00:00', $yearly->lastBefore(FloatingDateTime::of(2030, 1, 1))?->toString());
        self::assertNull($daily->firstAfter(FloatingDateTime::of(9999, 12, 31, 0, 0, 0)));
    }

    public function testExcludedDaysOfADateStart(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 1), new RecurrenceRule(Frequency::Daily, end: Count::of(3)))
            ->withExcludedDays(CalendarDate::of(2026, 1, 2));

        self::assertSame(['2026-01-01', '2026-01-03'], self::formatAll($recurrence));
        self::assertSame("DTSTART;VALUE=DATE:20260101\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEXDATE;VALUE=DATE:20260102", $recurrence->toString());
    }

    public function testAddedAndExcludedDatesInAnyOrder(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 1))
            ->withAddedDates(CalendarDate::of(2026, 1, 7), CalendarDate::of(2026, 1, 3), CalendarDate::of(2026, 1, 5))
            ->withExcludedDates(CalendarDate::of(2026, 1, 5), CalendarDate::of(2026, 1, 1));

        self::assertSame(['2026-01-03', '2026-01-07'], self::formatAll($recurrence));
    }

    /**
     * Each case is checked against the naive reference implementation. The start counts only when it matches.
     *
     * @param list<string> $expected
     */
    #[DataProvider('provideExpansionEdgeCases')]
    public function testExpansionEdgeCases(
        string $start,
        string $rule,
        array $expected,
    ): void
    {
        $recurrence = Recurrence::fromString("DTSTART:$start\nRRULE:$rule")->withDtStartPolicy(DtStartPolicy::OnlyIfMatching);

        self::assertSame($expected, self::formatAll($recurrence->take(count($expected))));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function provideExpansionEdgeCases(): iterable
    {
        yield 'canEverMatch: hour 0' => ['20260101T000000', 'FREQ=HOURLY;INTERVAL=24', ['2026-01-01T00:00:00', '2026-01-02T00:00:00', '2026-01-03T00:00:00']];
        yield 'canEverMatch: hour 23' => ['20260101T230000', 'FREQ=HOURLY;INTERVAL=24', ['2026-01-01T23:00:00', '2026-01-02T23:00:00', '2026-01-03T23:00:00']];
        yield 'canEverMatch: minute 0' => ['20260101T000000', 'FREQ=MINUTELY;INTERVAL=1440', ['2026-01-01T00:00:00', '2026-01-02T00:00:00', '2026-01-03T00:00:00']];
        yield 'canEverMatch: minute 59' => ['20260101T235900', 'FREQ=MINUTELY;INTERVAL=1440', ['2026-01-01T23:59:00', '2026-01-02T23:59:00', '2026-01-03T23:59:00']];
        yield 'canEverMatch: second 0' => ['20260101T000000', 'FREQ=SECONDLY;INTERVAL=86400', ['2026-01-01T00:00:00', '2026-01-02T00:00:00', '2026-01-03T00:00:00']];
        yield 'canEverMatch: second 59' => ['20260101T235959', 'FREQ=SECONDLY;INTERVAL=86400', ['2026-01-01T23:59:59', '2026-01-02T23:59:59', '2026-01-03T23:59:59']];
        yield 'canEverMatch: second within the day' => ['20260101T000030', 'FREQ=SECONDLY;INTERVAL=86400;BYMINUTE=0;BYSECOND=30', ['2026-01-01T00:00:30', '2026-01-02T00:00:30', '2026-01-03T00:00:30']];
        yield 'BYSETPOS: day 366 of a leap year' => ['20280101T000000', 'FREQ=YEARLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYSETPOS=366', ['2028-12-31T00:00:00', '2032-12-31T00:00:00']];
        yield 'BYSETPOS: day -366 of a leap year' => ['20280101T000000', 'FREQ=YEARLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYSETPOS=-366', ['2028-01-01T00:00:00', '2032-01-01T00:00:00']];
        yield 'BYSETPOS: yearly with two times of day' => ['20280101T000000', 'FREQ=YEARLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYHOUR=0,12;BYSETPOS=200', ['2028-04-09T12:00:00', '2029-04-10T12:00:00']];
        yield 'BYSETPOS: day 31 of a month' => ['20260101T000000', 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYSETPOS=31', ['2026-01-31T00:00:00', '2026-03-31T00:00:00']];
        yield 'BYSETPOS: monthly with two times of day' => ['20260101T000000', 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYHOUR=0,12;BYSETPOS=62', ['2026-01-31T12:00:00', '2026-03-31T12:00:00']];
        yield 'BYSETPOS: day 7 of a week' => ['20260105T000000', 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYSETPOS=7', ['2026-01-11T00:00:00', '2026-01-18T00:00:00']];
        yield 'BYSETPOS: weekly with two times of day' => ['20260105T000000', 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;BYHOUR=0,12;BYSETPOS=14', ['2026-01-11T12:00:00', '2026-01-18T12:00:00']];
        yield 'BYSETPOS: daily' => ['20260101T000000', 'FREQ=DAILY;BYHOUR=0,12;BYSETPOS=2', ['2026-01-01T12:00:00', '2026-01-02T12:00:00']];
        yield 'BYSETPOS: hourly' => ['20260101T000000', 'FREQ=HOURLY;BYMINUTE=0,30;BYSECOND=0,30;BYSETPOS=4', ['2026-01-01T00:30:30', '2026-01-01T01:30:30']];
        yield 'BYSETPOS: minutely' => ['20260101T000000', 'FREQ=MINUTELY;BYSECOND=0,30;BYSETPOS=2', ['2026-01-01T00:00:30', '2026-01-01T00:01:30']];
        yield 'BYSETPOS: secondly' => ['20260101T000000', 'FREQ=SECONDLY;BYMINUTE=0;BYSETPOS=1', ['2026-01-01T00:00:00', '2026-01-01T00:00:01', '2026-01-01T00:00:02']];
        yield 'BYSETPOS: duplicate BYHOUR values count once' => ['20260101T000000', 'FREQ=DAILY;BYHOUR=9,9,10;BYSETPOS=2', ['2026-01-01T10:00:00', '2026-01-02T10:00:00']];
        yield 'jump to January 1 of the next year' => ['20230101T000000', 'FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=1;BYDAY=MO', ['2024-01-01T00:00:00', '2029-01-01T00:00:00']];
        yield 'jump to the first day of the next week' => ['20270223T000000', 'FREQ=WEEKLY;BYDAY=MO;BYMONTH=3', ['2027-03-01T00:00:00', '2027-03-08T00:00:00']];
        yield 'weeks of INTERVAL=2 start on WKST' => ['20260105T000000', 'FREQ=WEEKLY;INTERVAL=2;WKST=TH;BYDAY=MO,TH', ['2026-01-05T00:00:00', '2026-01-15T00:00:00', '2026-01-19T00:00:00', '2026-01-29T00:00:00', '2026-02-02T00:00:00']];
        yield 'jump to the next hour' => ['20260101T090000', 'FREQ=HOURLY;BYHOUR=10,14', ['2026-01-01T10:00:00', '2026-01-01T14:00:00', '2026-01-02T10:00:00']];
        yield 'jump to the next minute' => ['20260101T092000', 'FREQ=MINUTELY;BYMINUTE=15,45', ['2026-01-01T09:45:00', '2026-01-01T10:15:00', '2026-01-01T10:45:00']];
        yield 'jump to the next day' => ['20260101T092000', 'FREQ=MINUTELY;BYHOUR=9;BYMINUTE=15', ['2026-01-02T09:15:00', '2026-01-03T09:15:00']];
        yield 'jump to the next second' => ['20260101T090020', 'FREQ=SECONDLY;BYSECOND=10,40', ['2026-01-01T09:00:40', '2026-01-01T09:01:10', '2026-01-01T09:01:40']];
        yield 'the last second of an hour' => ['20260101T005959', 'FREQ=SECONDLY;BYHOUR=1', ['2026-01-01T01:00:00', '2026-01-01T01:00:01']];
        yield 'start time in the last second of an hour' => ['20260101T005959', 'FREQ=DAILY', ['2026-01-01T00:59:59', '2026-01-02T00:59:59']];
        yield 'the last day of year 9999' => ['99990101T000000', 'FREQ=DAILY;BYMONTH=12;BYMONTHDAY=31', ['9999-12-31T00:00:00']];
        yield 'SKIP=FORWARD with an ordinal BYDAY in the year' => ['20260201T000000', 'RSCALE=GREGORIAN;FREQ=YEARLY;BYMONTHDAY=30;BYDAY=9MO;SKIP=FORWARD', ['2027-03-01T00:00:00', '2032-03-01T00:00:00']];
        yield 'SKIP=BACKWARD with a negative ordinal BYDAY in the year' => ['20260201T000000', 'RSCALE=GREGORIAN;FREQ=YEARLY;BYMONTHDAY=30;BYDAY=-44MO;SKIP=BACKWARD', ['2033-02-28T00:00:00', '2039-02-28T00:00:00']];
        yield 'SKIP=FORWARD with BYDAY: a moved date waits for the next match' => ['20260131T000000', 'RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=31;BYDAY=MO;SKIP=FORWARD', ['2026-08-31T00:00:00', '2027-03-01T00:00:00', '2027-05-31T00:00:00', '2028-01-31T00:00:00', '2028-05-01T00:00:00']];
        yield 'SKIP=FORWARD with BYDAY and UNTIL: a moved date is the last occurrence' => ['20260131T000000', 'RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=31;BYDAY=MO;SKIP=FORWARD;UNTIL=20270315T000000', ['2026-08-31T00:00:00', '2027-03-01T00:00:00']];
        yield 'SKIP=FORWARD from the period before the start does not count' => ['20260301T090000', 'RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=31;BYHOUR=10;SKIP=FORWARD', ['2026-03-31T10:00:00', '2026-05-01T10:00:00', '2026-05-31T10:00:00']];
        yield 'jump to minute 0 of the next hour' => ['20260101T094000', 'FREQ=MINUTELY;BYMINUTE=0,30', ['2026-01-01T10:00:00', '2026-01-01T10:30:00', '2026-01-01T11:00:00']];
        yield 'jump to hour 0 of the next day' => ['20260101T130000', 'FREQ=HOURLY;BYHOUR=0,12', ['2026-01-02T00:00:00', '2026-01-02T12:00:00']];
        yield 'SKIP=FORWARD to day 182 of the year' => ['20260601T000000', 'RSCALE=GREGORIAN;FREQ=YEARLY;BYMONTHDAY=31;BYDAY=26MO;SKIP=FORWARD', ['2030-07-01T00:00:00', '2041-07-01T00:00:00']];
        yield 'SKIP=FORWARD to day 274 of the year' => ['20260901T000000', 'RSCALE=GREGORIAN;FREQ=YEARLY;BYMONTHDAY=31;BYDAY=40MO;SKIP=FORWARD', ['2029-10-01T00:00:00', '2035-10-01T00:00:00']];
        yield 'SKIP=BACKWARD to day 120 of the year' => ['20260401T000000', 'RSCALE=GREGORIAN;FREQ=YEARLY;BYMONTHDAY=31;BYDAY=18TH;SKIP=BACKWARD', ['2026-04-30T00:00:00', '2037-04-30T00:00:00']];
        yield 'SKIP with BYWEEKNO drops the invalid day and keeps the next one' => ['20260101T000000', 'RSCALE=GREGORIAN;FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30,1;BYWEEKNO=4,5,6;SKIP=FORWARD', ['2026-02-01T00:00:00', '2027-02-01T00:00:00', '2028-02-01T00:00:00']];
    }

    public function testSearchStepsOfRareRules(): void
    {
        $rare = static fn (string $start, string $rule): Recurrence => Recurrence::fromString("DTSTART:$start\nRRULE:$rule")->withDtStartPolicy(DtStartPolicy::OnlyIfMatching);

        // February 30 never exists: the 400-year cycle check ends the search after about 100 leap years
        self::assertSame([], $rare('20260101T000000', 'FREQ=MONTHLY;BYMONTH=2;BYMONTHDAY=29,30;BYSETPOS=2')->withSearchLimit(150)->take(1));
        // every 168 hours from a Tuesday never meets a Monday: the cycle check ends the search after 400 years
        self::assertSame([], $rare('20260106T000000', 'FREQ=HOURLY;INTERVAL=168;BYDAY=MO')->withSearchLimit(30_000)->take(1));
        // SKIP does not apply to DAILY, so empty periods are skipped in one jump
        self::assertSame(['2028-02-29T00:00:00'], self::formatAll($rare('20260101T000000', 'RSCALE=GREGORIAN;FREQ=DAILY;BYMONTH=2;BYMONTHDAY=29;SKIP=FORWARD')->withSearchLimit(5)->take(1)));
        // BYSECOND=60 matches no time, which is known without a search
        self::assertSame([], $rare('20260101T000000', 'FREQ=HOURLY;BYSECOND=60')->withSearchLimit(1)->take(1));

        $hourly = $rare('20260102T000000', 'FREQ=HOURLY;BYMONTHDAY=1');
        self::assertSame(['2026-02-01T00:00:00'], self::formatAll($hourly->withSearchLimit(2)->take(1)));
        $this->expectException(SearchLimitExceededException::class);
        $hourly->withSearchLimit(1)->take(1);
    }

    public function testBetweenBeforeTheStart(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 1), new RecurrenceRule(Frequency::Daily));

        self::assertSame([], self::formatAll($recurrence->between(CalendarDate::of(2025, 1, 1), CalendarDate::of(2026, 1, 1))));
        self::assertSame(['2026-01-01'], self::formatAll($recurrence->between(CalendarDate::of(2025, 1, 1), CalendarDate::of(2026, 1, 2))));
    }

    public function testStringRoundTrip(): void
    {
        $text = "DTSTART;TZID=Europe/Prague:20260105T090000\r\nRRULE:FREQ=WEEKLY;BYDAY=MO,TH\r\nEXDATE;TZID=Europe/Prague:20260108T090000";
        $recurrence = Recurrence::fromString($text);

        self::assertSame($text, $recurrence->toString());
        self::assertSame(str_replace("\r\n", "\n", $text), $recurrence->toString("\n"));
        self::assertSame(['2026-01-05T09:00:00+01:00', '2026-01-12T09:00:00+01:00'], self::formatAll($recurrence->take(2)));
    }

    /**
     * @param iterable<CalendarDate|FloatingDateTime|DateTimeImmutable> $values
     * @return list<string>
     */
    private static function formatAll(iterable $values): array
    {
        $result = [];

        foreach ($values as $value) {
            $result[] = $value instanceof DateTimeImmutable ? $value->format('c') : $value->toString();
        }

        return $result;
    }

}
