<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Serializer;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\CalendarScale;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Internal\RuleSerializer;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function explode;
use function str_repeat;
use function str_replace;
use function strlen;

#[CoversClass(RuleSerializer::class)]
final class RuleSerializerTest extends TestCase
{

    #[DataProvider('provideRules')]
    public function testSerializeRule(
        RecurrenceRule $rule,
        string $expected,
    ): void
    {
        self::assertSame($expected, RuleSerializer::serializeRule($rule));
    }

    /**
     * @return array<string, array{RecurrenceRule, string}>
     */
    public static function provideRules(): array
    {
        return [
            'frequency only' => [new RecurrenceRule(Frequency::Daily), 'FREQ=DAILY'],
            'default values are left out' => [new RecurrenceRule(Frequency::Weekly, interval: 1, weekStart: Weekday::Monday, skip: Skip::Omit), 'FREQ=WEEKLY'],

            'every part in canonical order' => [
                new RecurrenceRule(
                    frequency: Frequency::Yearly,
                    interval: 2,
                    end: Count::of(5),
                    bySecond: [0, 60],
                    byMinute: [1],
                    byHour: [2, 23],
                    byDay: [WeekdayNum::every(Weekday::Monday), WeekdayNum::every(Weekday::Friday)],
                    byMonthDay: [1, -1],
                    byYearDay: [1, -366],
                    byWeekNo: [1, -53],
                    byMonth: [12, 1],
                    bySetPos: [1, -1],
                    weekStart: Weekday::Sunday,
                    calendarScale: CalendarScale::Gregorian,
                    skip: Skip::Forward,
                ),
                'FREQ=YEARLY;RSCALE=GREGORIAN;INTERVAL=2;COUNT=5;BYSECOND=0,60;BYMINUTE=1;BYHOUR=2,23;BYDAY=MO,FR;BYMONTHDAY=1,-1;BYYEARDAY=1,-366;BYWEEKNO=1,-53;BYMONTH=12,1;BYSETPOS=1,-1;WKST=SU;SKIP=FORWARD',
            ],

            'BYDAY ordinals' => [
                new RecurrenceRule(Frequency::Monthly, byDay: [WeekdayNum::of(Weekday::Monday, 1), WeekdayNum::of(Weekday::Friday, -1), WeekdayNum::of(Weekday::Sunday, 53)]),
                'FREQ=MONTHLY;BYDAY=1MO,-1FR,53SU',
            ],

            'RSCALE without SKIP' => [new RecurrenceRule(Frequency::Monthly, calendarScale: CalendarScale::Gregorian), 'FREQ=MONTHLY;RSCALE=GREGORIAN'],
            'SKIP=BACKWARD' => [new RecurrenceRule(Frequency::Monthly, calendarScale: CalendarScale::Gregorian, skip: Skip::Backward), 'FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD'],
            'UNTIL date in year 1' => [new RecurrenceRule(Frequency::Daily, end: Until::date(CalendarDate::of(1, 2, 3))), 'FREQ=DAILY;UNTIL=00010203'],
            'UNTIL floating' => [new RecurrenceRule(Frequency::Daily, end: Until::floating(FloatingDateTime::of(2026, 1, 31, 8, 5, 3))), 'FREQ=DAILY;UNTIL=20260131T080503'],
            'UNTIL instant' => [
                new RecurrenceRule(Frequency::Daily, end: Until::instant(new DateTimeImmutable('2026-01-01 00:30:00', new DateTimeZone('Europe/Prague')))),
                'FREQ=DAILY;UNTIL=20251231T233000Z',
            ],
            'UNTIL last instant' => [
                new RecurrenceRule(Frequency::Daily, end: Until::instant(new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC')))),
                'FREQ=DAILY;UNTIL=99991231T235959Z',
            ],
            'WKST' => [new RecurrenceRule(Frequency::Weekly, weekStart: Weekday::Saturday), 'FREQ=WEEKLY;WKST=SA'],
            'INTERVAL' => [new RecurrenceRule(Frequency::Hourly, interval: 36), 'FREQ=HOURLY;INTERVAL=36'],
        ];
    }

    public function testDateRecurrence(): void
    {
        $recurrence = Recurrence::of(CalendarDate::of(2026, 1, 31), new RecurrenceRule(Frequency::Daily, end: Count::of(5)), new RecurrenceRule(Frequency::Weekly))
            ->withAddedDates(CalendarDate::of(2026, 3, 1), CalendarDate::of(2026, 4, 1))
            ->withExcludedDates(CalendarDate::of(2026, 2, 1))
            ->withExcludedDays(CalendarDate::of(2026, 2, 2))
            ->withExclusionRules(new RecurrenceRule(Frequency::Monthly), new RecurrenceRule(Frequency::Yearly));

        self::assertSame(
            "DTSTART;VALUE=DATE:20260131\r\n"
            . "RRULE:FREQ=DAILY;COUNT=5\r\n"
            . "RRULE:FREQ=WEEKLY\r\n"
            . "RDATE;VALUE=DATE:20260301,20260401\r\n"
            . "EXDATE;VALUE=DATE:20260201,20260202\r\n"
            . "EXRULE:FREQ=MONTHLY\r\n"
            . 'EXRULE:FREQ=YEARLY',
            RuleSerializer::serializeRecurrence($recurrence, "\r\n"),
        );
    }

    public function testFloatingRecurrence(): void
    {
        $recurrence = Recurrence::of(FloatingDateTime::of(2026, 1, 31, 9), new RecurrenceRule(Frequency::Daily, end: Until::floating(FloatingDateTime::of(2026, 2, 28, 9))))
            ->withAddedDates(FloatingDateTime::of(2026, 3, 1, 10))
            ->withExcludedDates(FloatingDateTime::of(2026, 2, 1, 9), FloatingDateTime::of(2026, 2, 2, 9))
            ->withExcludedDays(CalendarDate::of(2026, 2, 3));

        self::assertSame(
            "DTSTART:20260131T090000\n"
            . "RRULE:FREQ=DAILY;UNTIL=20260228T090000\n"
            . "RDATE:20260301T100000\n"
            . "EXDATE:20260201T090000,20260202T090000\n"
            . 'EXDATE;VALUE=DATE:20260203',
            RuleSerializer::serializeRecurrence($recurrence, "\n"),
        );
    }

    public function testUtcRecurrence(): void
    {
        $utc = new DateTimeZone('UTC');
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-01-31 09:00:00', $utc), new RecurrenceRule(Frequency::Daily))
            ->withAddedDates(new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('Europe/Prague')))
            ->withExcludedDates(new DateTimeImmutable('2026-02-01 09:00:00', $utc));

        self::assertSame(
            "DTSTART:20260131T090000Z\n"
            . "RRULE:FREQ=DAILY\n"
            . "RDATE:20260301T090000Z\n"
            . 'EXDATE:20260201T090000Z',
            RuleSerializer::serializeRecurrence($recurrence, "\n"),
        );
    }

    public function testZonedRecurrence(): void
    {
        $prague = new DateTimeZone('Europe/Prague');
        $secondOverlap = (new DateTimeImmutable('2026-10-25 01:30:00', new DateTimeZone('UTC')))->setTimezone($prague);
        $firstOverlap = (new DateTimeImmutable('2026-10-25 00:30:00', new DateTimeZone('UTC')))->setTimezone($prague);
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-01-31 09:00:00', $prague), new RecurrenceRule(Frequency::Daily))
            ->withAddedDates(new DateTimeImmutable('2026-02-01 10:00:00', new DateTimeZone('America/New_York')), $secondOverlap, $firstOverlap)
            ->withExcludedDates($secondOverlap, new DateTimeImmutable('2026-02-02 09:00:00', $prague))
            ->withExcludedDays(CalendarDate::of(2026, 2, 3));

        self::assertSame(
            "DTSTART;TZID=Europe/Prague:20260131T090000\n"
            . "RRULE:FREQ=DAILY\n"
            . "RDATE;TZID=Europe/Prague:20260201T160000,20261025T023000\n"
            . "RDATE:20261025T013000Z\n"
            . "EXDATE:20261025T013000Z\n"
            . "EXDATE;TZID=Europe/Prague:20260202T090000\n"
            . 'EXDATE;VALUE=DATE:20260203',
            RuleSerializer::serializeRecurrence($recurrence, "\n"),
        );
    }

    public function testStartOnly(): void
    {
        $recurrence = Recurrence::of(new DateTimeImmutable('2026-07-01 09:00:00', new DateTimeZone('America/New_York')));

        self::assertSame('DTSTART;TZID=America/New_York:20260701T090000', RuleSerializer::serializeRecurrence($recurrence, "\r\n"));
    }

    public function testLongLinesAreFolded(): void
    {
        $dates = [];

        for ($day = 1; $day <= 9; $day++) {
            $dates[] = FloatingDateTime::of(2026, 2, $day, 9);
        }

        $recurrence = Recurrence::of(FloatingDateTime::of(2026, 1, 31, 9))->withAddedDates(...$dates);
        $text = RuleSerializer::serializeRecurrence($recurrence, "\r\n");

        self::assertSame(
            "DTSTART:20260131T090000\r\n"
            . "RDATE:20260201T090000,20260202T090000,20260203T090000,20260204T090000,20260\r\n"
            . ' 205T090000,20260206T090000,20260207T090000,20260208T090000,20260209T090000',
            $text,
        );

        foreach (explode("\r\n", $text) as $physicalLine) {
            self::assertLessThanOrEqual(75, strlen($physicalLine));
        }
    }

    /**
     * @param list<string> $expectedLines
     */
    #[DataProvider('provideFolding')]
    public function testFold(
        string $line,
        array $expectedLines,
    ): void
    {
        $folded = RuleSerializer::fold($line, "\r\n");

        self::assertSame($expectedLines, explode("\r\n", $folded));
        self::assertSame($line, str_replace("\r\n ", '', $folded));

        foreach ($expectedLines as $physicalLine) {
            self::assertLessThanOrEqual(75, strlen($physicalLine));
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideFolding(): iterable
    {
        yield 'empty' => ['', ['']];
        yield '75 octets' => [str_repeat('a', 75), [str_repeat('a', 75)]];
        yield '76 octets' => [str_repeat('a', 76), [str_repeat('a', 75), ' a']];
        yield '150 octets' => [str_repeat('a', 150), [str_repeat('a', 75), ' ' . str_repeat('a', 74), ' a']];
        yield '151 octets' => [str_repeat('a', 151), [str_repeat('a', 75), ' ' . str_repeat('a', 74), ' aa']];
        yield '2-octet character across the limit' => [str_repeat('a', 74) . "\u{E9}", [str_repeat('a', 74), " \u{E9}"]];
        yield '2-octet character before the limit' => [str_repeat('a', 73) . "\u{E9}b", [str_repeat('a', 73) . "\u{E9}", ' b']];
        yield '3-octet character across the limit' => [str_repeat('a', 73) . "\u{20AC}", [str_repeat('a', 73), " \u{20AC}"]];
        yield '3-octet character at the limit' => [str_repeat('a', 74) . "\u{20AC}", [str_repeat('a', 74), " \u{20AC}"]];
        yield '4-octet character across the limit' => [str_repeat('a', 72) . "\u{1F600}", [str_repeat('a', 72), " \u{1F600}"]];
        yield '4-octet character at the limit' => [str_repeat('a', 73) . "\u{1F600}", [str_repeat('a', 73), " \u{1F600}"]];
        yield '2-octet characters on several lines' => [
            str_repeat("\u{E9}", 100),
            [str_repeat("\u{E9}", 37), ' ' . str_repeat("\u{E9}", 37), ' ' . str_repeat("\u{E9}", 26)],
        ];
        yield 'octet 0xC0 is not a continuation octet' => [str_repeat('a', 75) . "\xC0\x80", [str_repeat('a', 75), " \xC0\x80"]];
        yield 'invalid UTF-8 is cut after 3 continuation octets' => ['a' . str_repeat("\x80", 80), ['a' . str_repeat("\x80", 71), ' ' . str_repeat("\x80", 9)]];
    }

    public function testFormatUntil(): void
    {
        self::assertSame('20260131', RuleSerializer::formatUntil(Until::date(CalendarDate::of(2026, 1, 31))));
        self::assertSame('20260131T235959', RuleSerializer::formatUntil(Until::floating(FloatingDateTime::of(2026, 1, 31, 23, 59, 59))));
        self::assertSame('20260131T225959Z', RuleSerializer::formatUntil(Until::instant(new DateTimeImmutable('2026-01-31 23:59:59', new DateTimeZone('Europe/Prague')))));
    }

    public function testFoldUsesTheLineBreak(): void
    {
        self::assertSame(str_repeat('a', 75) . "\n a", RuleSerializer::fold(str_repeat('a', 76), "\n"));
    }

    #[DataProvider('provideParameterValues')]
    public function testFormatParameterValue(
        string $value,
        string $expected,
    ): void
    {
        self::assertSame($expected, RuleSerializer::formatParameterValue($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideParameterValues(): iterable
    {
        yield 'plain' => ['Europe/Prague', 'Europe/Prague'];
        yield 'empty' => ['', ''];
        yield 'colon' => ['/example.com:Europe/Prague', '"/example.com:Europe/Prague"'];
        yield 'semicolon' => ['a;b', '"a;b"'];
        yield 'comma' => ['a,b', '"a,b"'];
    }

}
