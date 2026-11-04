<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Serializer;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Internal\Parser\ContentLine;
use ShipMonk\RRule\Internal\Parser\ContentLineReader;
use ShipMonk\RRule\Internal\Parser\ParseContext;
use ShipMonk\RRule\Internal\Parser\ParseErrors;
use ShipMonk\RRule\Internal\Parser\RawRule;
use ShipMonk\RRule\Internal\Parser\RecurrenceTextParser;
use ShipMonk\RRule\Internal\Parser\RuleParser;
use ShipMonk\RRule\Internal\Parser\ValueParser;
use ShipMonk\RRule\Internal\RuleSerializer;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_map;
use function sort;

/**
 * parse(serialize(x)) equals x, and serialize(parse(text)) equals the canonical text.
 */
#[CoversClass(RuleSerializer::class)]
#[CoversClass(RecurrenceParser::class)]
#[CoversClass(RecurrenceTextParser::class)]
#[CoversClass(RuleParser::class)]
#[CoversClass(RawRule::class)]
#[CoversClass(ValueParser::class)]
#[CoversClass(ParseContext::class)]
#[CoversClass(ParseErrors::class)]
#[CoversClass(ContentLine::class)]
#[CoversClass(ContentLineReader::class)]
final class RoundTripTest extends TestCase
{

    #[DataProvider('provideCanonicalRules')]
    public function testRuleRoundTrip(string $canonical): void
    {
        $parser = new RecurrenceParser();
        $rule = $parser->parseRule($canonical)->value;

        self::assertSame($canonical, RuleSerializer::serializeRule($rule));
        self::assertTrue($parser->parseRule(RuleSerializer::serializeRule($rule))->value->equals($rule));
        self::assertTrue($parser->parseRule('RRULE:' . $canonical)->value->equals($rule));
    }

    /**
     * Canonical forms of the RFC 5545 section 3.8.5.3 examples, the RFC 7529 examples and edge values.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideCanonicalRules(): iterable
    {
        $rules = [
            'FREQ=DAILY;COUNT=10',
            'FREQ=DAILY;UNTIL=19971224T000000Z',
            'FREQ=DAILY;INTERVAL=2',
            'FREQ=DAILY;INTERVAL=10;COUNT=5',
            'FREQ=YEARLY;UNTIL=20000131T140000Z;BYDAY=SU,MO,TU,WE,TH,FR,SA;BYMONTH=1',
            'FREQ=DAILY;UNTIL=20000131T140000Z;BYMONTH=1',
            'FREQ=WEEKLY;COUNT=10',
            'FREQ=WEEKLY;UNTIL=19971224T000000Z',
            'FREQ=WEEKLY;INTERVAL=2;WKST=SU',
            'FREQ=WEEKLY;UNTIL=19971007T000000Z;BYDAY=TU,TH;WKST=SU',
            'FREQ=WEEKLY;COUNT=10;BYDAY=TU,TH;WKST=SU',
            'FREQ=WEEKLY;INTERVAL=2;UNTIL=19971224T000000Z;BYDAY=MO,WE,FR;WKST=SU',
            'FREQ=WEEKLY;INTERVAL=2;COUNT=8;BYDAY=TU,TH;WKST=SU',
            'FREQ=MONTHLY;COUNT=10;BYDAY=1FR',
            'FREQ=MONTHLY;UNTIL=19971224T000000Z;BYDAY=1FR',
            'FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU',
            'FREQ=MONTHLY;COUNT=6;BYDAY=-2MO',
            'FREQ=MONTHLY;BYMONTHDAY=-3',
            'FREQ=MONTHLY;COUNT=10;BYMONTHDAY=2,15',
            'FREQ=MONTHLY;COUNT=10;BYMONTHDAY=1,-1',
            'FREQ=MONTHLY;INTERVAL=18;COUNT=10;BYMONTHDAY=10,11,12,13,14,15',
            'FREQ=MONTHLY;INTERVAL=2;BYDAY=TU',
            'FREQ=YEARLY;COUNT=10;BYMONTH=6,7',
            'FREQ=YEARLY;INTERVAL=2;COUNT=10;BYMONTH=1,2,3',
            'FREQ=YEARLY;INTERVAL=3;COUNT=10;BYYEARDAY=1,100,200',
            'FREQ=YEARLY;BYDAY=20MO',
            'FREQ=YEARLY;BYDAY=MO;BYWEEKNO=20',
            'FREQ=YEARLY;BYDAY=TH;BYMONTH=3',
            'FREQ=YEARLY;BYDAY=TH;BYMONTH=6,7,8',
            'FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13',
            'FREQ=MONTHLY;BYDAY=SA;BYMONTHDAY=7,8,9,10,11,12,13',
            'FREQ=YEARLY;INTERVAL=4;BYDAY=TU;BYMONTHDAY=2,3,4,5,6,7,8;BYMONTH=11',
            'FREQ=MONTHLY;COUNT=3;BYDAY=TU,WE,TH;BYSETPOS=3',
            'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-2',
            'FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T210000Z',
            'FREQ=MINUTELY;INTERVAL=15;COUNT=6',
            'FREQ=MINUTELY;INTERVAL=90;COUNT=4',
            'FREQ=DAILY;BYMINUTE=0,20,40;BYHOUR=9,10,11,12,13,14,15,16',
            'FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10,11,12,13,14,15,16',
            'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU',
            'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU',
            'FREQ=MONTHLY;COUNT=5;BYMONTHDAY=15,30',
            'FREQ=MONTHLY;COUNT=5;BYMONTHDAY=-1',
            'FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=FORWARD',
            'FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=31;SKIP=BACKWARD',
            'FREQ=YEARLY;RSCALE=GREGORIAN;COUNT=5;BYMONTHDAY=29;BYMONTH=2;SKIP=FORWARD',
            'FREQ=MONTHLY;RSCALE=GREGORIAN',
            'FREQ=YEARLY;RSCALE=GREGORIAN;UNTIL=20300101;BYMONTHDAY=29;BYMONTH=2;SKIP=BACKWARD',
            'FREQ=DAILY;RSCALE=GREGORIAN;SKIP=BACKWARD',
            'FREQ=WEEKLY;RSCALE=GREGORIAN;UNTIL=20261231T235959;BYDAY=MO;WKST=SU',
            'FREQ=SECONDLY',
            'FREQ=SECONDLY;INTERVAL=86400;BYSECOND=60',
            'FREQ=SECONDLY;INTERVAL=2;UNTIL=20260101T000000Z;BYSECOND=60',
            'FREQ=MINUTELY;BYSECOND=0,15,30,45,60',
            'FREQ=MINUTELY;COUNT=3;BYMINUTE=0,30;BYDAY=MO',
            'FREQ=HOURLY;BYSECOND=0,60;BYMINUTE=0,59',
            'FREQ=HOURLY;INTERVAL=25;BYHOUR=1,3,5',
            'FREQ=HOURLY;BYYEARDAY=1,-1',
            'FREQ=HOURLY;BYDAY=SA,SU;BYMONTHDAY=1',
            'FREQ=DAILY;BYSECOND=59;BYMINUTE=59;BYHOUR=23',
            'FREQ=DAILY;BYHOUR=0',
            'FREQ=DAILY;BYDAY=MO,WE,FR',
            'FREQ=DAILY;BYMONTHDAY=1,15',
            'FREQ=DAILY;BYMONTH=12;BYSETPOS=1',
            'FREQ=WEEKLY;BYMONTH=1,2;BYSETPOS=-1',
            'FREQ=WEEKLY;BYHOUR=8,17;BYDAY=MO',
            'FREQ=MONTHLY;BYDAY=5FR',
            'FREQ=MONTHLY;BYDAY=-5TH,1MO,SA',
            'FREQ=MONTHLY;BYDAY=-1MO,-2MO,-3MO;BYSETPOS=1,2',
            'FREQ=MONTHLY;BYMONTHDAY=1;BYSETPOS=366',
            'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-366',
            'FREQ=MONTHLY;INTERVAL=2;BYDAY=1MO,1TU,1WE,1TH,1FR,1SA,1SU',
            'FREQ=YEARLY;BYDAY=53MO',
            'FREQ=YEARLY;BYDAY=-53SU',
            'FREQ=YEARLY;BYYEARDAY=366',
            'FREQ=YEARLY;BYYEARDAY=-366,-1',
            'FREQ=YEARLY;BYWEEKNO=53',
            'FREQ=YEARLY;BYWEEKNO=-53,-1,1',
            'FREQ=YEARLY;BYMONTHDAY=31;BYMONTH=1,3,5,7,8,10,12',
            'FREQ=YEARLY;BYMONTHDAY=-31',
            'FREQ=YEARLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1,2,-1,-2',
            'FREQ=YEARLY;BYMONTH=12,11,10,9,8,7,6,5,4,3,2,1',
            'FREQ=YEARLY;BYMONTH=2,2',
            'FREQ=YEARLY;BYDAY=MO;BYWEEKNO=1;BYMONTH=12;BYSETPOS=1',
            'FREQ=YEARLY;BYMONTHDAY=30;BYWEEKNO=1;BYMONTH=12',
            'FREQ=YEARLY;BYSECOND=0;BYMINUTE=0;BYHOUR=0;BYDAY=MO;BYMONTHDAY=1;BYYEARDAY=1;BYWEEKNO=1;BYMONTH=1;BYSETPOS=1;WKST=SU',
            'FREQ=YEARLY;RSCALE=GREGORIAN;INTERVAL=2;COUNT=3;BYSECOND=1;BYMINUTE=2;BYHOUR=3;BYDAY=-1FR;BYMONTHDAY=-1;BYYEARDAY=100;BYMONTH=2;BYSETPOS=1;WKST=SU;SKIP=FORWARD',
            'FREQ=YEARLY;INTERVAL=400',
            'FREQ=DAILY;INTERVAL=999999999999999999',
            'FREQ=DAILY;COUNT=999999999999999999',
            'FREQ=DAILY;COUNT=1',
            'FREQ=DAILY;UNTIL=00010101',
            'FREQ=DAILY;UNTIL=99991231',
            'FREQ=DAILY;UNTIL=99991231T235959',
            'FREQ=DAILY;UNTIL=00010101T000000Z',
            'FREQ=DAILY;UNTIL=99991231T235959Z',
            'FREQ=WEEKLY;UNTIL=20280229T120000',
            'FREQ=YEARLY;WKST=TU',
            'FREQ=WEEKLY;WKST=WE',
            'FREQ=WEEKLY;WKST=TH',
            'FREQ=WEEKLY;WKST=FR',
            'FREQ=WEEKLY;WKST=SA',
        ];

        foreach ($rules as $rule) {
            yield $rule => [$rule];
        }
    }

    public function testRulesBuiltInCode(): void
    {
        $parser = new RecurrenceParser();
        $rules = [];

        foreach (Frequency::cases() as $frequency) {
            foreach (Weekday::cases() as $weekday) {
                $rules[] = new RecurrenceRule($frequency, byDay: [WeekdayNum::every($weekday)], weekStart: $weekday);
                $rules[] = new RecurrenceRule($frequency, interval: $weekday->getIsoNumber() + 1, end: Count::of($weekday->getIsoNumber()), byMonth: [$weekday->getIsoNumber()]);
            }
        }

        foreach ($rules as $rule) {
            $parsed = $parser->parseRule(RuleSerializer::serializeRule($rule))->value;

            self::assertSame($rule->frequency, $parsed->frequency);
            self::assertSame($rule->interval, $parsed->interval);
            self::assertSame($rule->weekStart, $parsed->weekStart);
            self::assertSame($rule->byMonth, $parsed->byMonth);
            self::assertTrue($parsed->equals($rule));
        }
    }

    /**
     * @param Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable> $recurrence
     */
    #[DataProvider('provideRecurrences')]
    public function testRecurrenceRoundTrip(Recurrence $recurrence): void
    {
        foreach (["\r\n", "\n"] as $lineBreak) {
            $text = RuleSerializer::serializeRecurrence($recurrence, $lineBreak);
            $parsed = (new RecurrenceParser(ParseMode::Lenient))->parseRecurrence($text)->value;

            self::assertSame($text, RuleSerializer::serializeRecurrence($parsed, $lineBreak));
            self::assertSame(self::describe($recurrence->getStart()), self::describe($parsed->getStart()));
            self::assertSame(self::describeRules($recurrence->getRules()), self::describeRules($parsed->getRules()));
            self::assertSame(self::describeRules($recurrence->getExclusionRules()), self::describeRules($parsed->getExclusionRules()));
            self::assertSame(self::describeAll($recurrence->getAddedDates()), self::describeAll($parsed->getAddedDates()));

            if ($recurrence->getStart() instanceof LocalDate) {
                self::assertSame(
                    self::describeAll([...$recurrence->getExcludedDates(), ...$recurrence->getExcludedDays()]),
                    self::describeAll($parsed->getExcludedDates()),
                );
            } else {
                self::assertSame(self::describeAll($recurrence->getExcludedDates()), self::describeAll($parsed->getExcludedDates()));
                self::assertSame(self::describeAll($recurrence->getExcludedDays()), self::describeAll($parsed->getExcludedDays()));
            }
        }
    }

    /**
     * @return array<string, array{Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable>}>
     */
    public static function provideRecurrences(): array
    {
        $utc = new DateTimeZone('UTC');
        $prague = new DateTimeZone('Europe/Prague');
        $newYork = new DateTimeZone('America/New_York');
        $secondOverlap = (new DateTimeImmutable('2026-11-01 06:30:00', $utc))->setTimezone($newYork);
        $dates = [];

        for ($day = 1; $day <= 28; $day++) {
            $dates[] = new DateTimeImmutable('2026-02-' . $day . ' 09:00:00', new DateTimeZone('America/Argentina/Buenos_Aires'));
        }

        return [
            'date start only' => [Recurrence::of(LocalDate::of(2026, 1, 31))],

            'date start with everything' => [
                Recurrence::of(
                    LocalDate::of(2026, 1, 31),
                    new RecurrenceRule(Frequency::Monthly, end: Until::date(LocalDate::of(2027, 1, 31)), byMonthDay: [-1]),
                    new RecurrenceRule(Frequency::Yearly, end: Count::of(3)),
                )
                    ->withAddedDates(LocalDate::of(1, 1, 1), LocalDate::of(9999, 12, 31))
                    ->withExcludedDates(LocalDate::of(2026, 2, 28))
                    ->withExcludedDays(LocalDate::of(2026, 3, 31))
                    ->withExclusionRules(new RecurrenceRule(Frequency::Weekly, byDay: [WeekdayNum::every(Weekday::Sunday)])),
            ],

            'floating start with everything' => [
                Recurrence::of(
                    LocalDateTime::of(2026, 1, 31, 9, 30, 15),
                    new RecurrenceRule(Frequency::Hourly, interval: 5, end: Until::local(LocalDateTime::of(2026, 2, 1))),
                )
                    ->withAddedDates(LocalDateTime::of(1, 1, 1), LocalDateTime::of(9999, 12, 31, 23, 59, 59))
                    ->withExcludedDates(LocalDateTime::of(2026, 1, 31, 14, 30, 15))
                    ->withExcludedDays(LocalDate::of(2026, 2, 1))
                    ->withExclusionRules(new RecurrenceRule(Frequency::Daily, byHour: [3])),
            ],

            'UTC start with everything' => [
                Recurrence::of(
                    new DateTimeImmutable('2026-01-31 09:00:00', $utc),
                    new RecurrenceRule(Frequency::Weekly, end: Until::instant(new DateTimeImmutable('2026-06-30 09:00:00', $utc))),
                    new RecurrenceRule(Frequency::Monthly, byDay: [WeekdayNum::of(Weekday::Friday, -1)]),
                )
                    ->withAddedDates(new DateTimeImmutable('2026-02-03 10:00:00', $prague), new DateTimeImmutable('2026-02-04 10:00:00', $utc))
                    ->withExcludedDates(new DateTimeImmutable('2026-02-07 09:00:00', $utc))
                    ->withExcludedDays(LocalDate::of(2026, 2, 14)),
            ],

            'zoned start with repeated local times' => [
                Recurrence::of(
                    new DateTimeImmutable('2026-10-31 01:30:00', $newYork),
                    new RecurrenceRule(Frequency::Daily, end: Until::instant(new DateTimeImmutable('2026-11-10 06:30:00', $utc))),
                )
                    ->withAddedDates($secondOverlap, new DateTimeImmutable('2026-11-01 05:30:00', $utc), new DateTimeImmutable('2026-11-20 09:00:00', $prague))
                    ->withExcludedDates($secondOverlap, new DateTimeImmutable('2026-11-02 01:30:00', $newYork))
                    ->withExcludedDays(LocalDate::of(2026, 11, 3), LocalDate::of(2026, 11, 4))
                    ->withExclusionRules(new RecurrenceRule(Frequency::Weekly, byDay: [WeekdayNum::every(Weekday::Saturday)])),
            ],

            'zoned start with long folded lines' => [
                Recurrence::of(new DateTimeImmutable('2026-01-31 09:00:00', new DateTimeZone('America/Argentina/Buenos_Aires')))
                    ->withAddedDates(...$dates)
                    ->withExcludedDates(...$dates),
            ],

            'zoned start in a DST gap' => [
                Recurrence::of(
                    new DateTimeImmutable('2026-03-29 03:30:00', $prague),
                    new RecurrenceRule(Frequency::Yearly, byMonth: [3], byDay: [WeekdayNum::of(Weekday::Sunday, -1)]),
                ),
            ],

            'UTC start with many rules' => [
                Recurrence::of(
                    new DateTimeImmutable('2026-01-01 00:00:00', $utc),
                    new RecurrenceRule(Frequency::Secondly, bySecond: [60]),
                    new RecurrenceRule(Frequency::Minutely, interval: 90),
                    new RecurrenceRule(Frequency::Yearly, byWeekNo: [-53, 53], byDay: [WeekdayNum::every(Weekday::Thursday)]),
                ),
            ],
        ];
    }

    /**
     * @param list<RecurrenceRule> $rules
     * @return list<string>
     */
    private static function describeRules(array $rules): array
    {
        return array_map(static fn (RecurrenceRule $rule): string => $rule->toString(), $rules);
    }

    /**
     * The serializer groups values by their form, so the order can change; the set does not.
     *
     * @param list<LocalDate|LocalDateTime|DateTimeImmutable> $values
     * @return list<string>
     */
    private static function describeAll(array $values): array
    {
        $descriptions = array_map(self::describe(...), $values);
        sort($descriptions);

        return $descriptions;
    }

    private static function describe(LocalDate|LocalDateTime|DateTimeImmutable $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d\TH:i:sP e U');
        }

        return $value->toString() . ($value instanceof LocalDate ? ' date' : ' floating');
    }

}
