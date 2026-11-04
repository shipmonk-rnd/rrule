<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Parser;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\CalendarScale;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\Exception\InvalidRecurrenceRuleException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Internal\Parser\ContentLineReader;
use ShipMonk\RRule\Internal\Parser\ParseContext;
use ShipMonk\RRule\Internal\Parser\ParseErrors;
use ShipMonk\RRule\Internal\Parser\RawRule;
use ShipMonk\RRule\Internal\Parser\RuleParser;
use ShipMonk\RRule\Internal\Parser\ValueParser;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use Throwable;

#[CoversClass(RecurrenceParser::class)]
#[CoversClass(RuleParser::class)]
#[CoversClass(RawRule::class)]
#[CoversClass(ValueParser::class)]
#[CoversClass(ParseContext::class)]
#[CoversClass(ParseErrors::class)]
#[CoversClass(ContentLineReader::class)]
final class RuleParsingTest extends ParserTestCase
{

    #[DataProvider('provideValidRules')]
    public function testValidRule(
        string $input,
        string $expected,
    ): void
    {
        foreach ([ParseMode::Strict, ParseMode::Lenient] as $mode) {
            $result = (new RecurrenceParser($mode))->parseRule($input);

            self::assertSame($expected, $result->value->toString());
            self::assertSame([], $result->warnings);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideValidRules(): iterable
    {
        yield 'secondly' => ['FREQ=SECONDLY', 'FREQ=SECONDLY'];
        yield 'minutely' => ['FREQ=MINUTELY', 'FREQ=MINUTELY'];
        yield 'hourly' => ['FREQ=HOURLY', 'FREQ=HOURLY'];
        yield 'daily' => ['FREQ=DAILY', 'FREQ=DAILY'];
        yield 'weekly' => ['FREQ=WEEKLY', 'FREQ=WEEKLY'];
        yield 'monthly' => ['FREQ=MONTHLY', 'FREQ=MONTHLY'];
        yield 'yearly' => ['FREQ=YEARLY', 'FREQ=YEARLY'];
        yield 'RRULE prefix' => ['RRULE:FREQ=DAILY', 'FREQ=DAILY'];
        yield 'lower-case RRULE prefix' => ['rrule:FREQ=DAILY', 'FREQ=DAILY'];
        yield 'names and values in lower case' => ['freq=monthly;byday=mo,-1fr;wkst=su;until=20261231t235959z', 'FREQ=MONTHLY;UNTIL=20261231T235959Z;BYDAY=MO,-1FR;WKST=SU'];
        yield 'surrounding whitespace and line break' => [" \t FREQ=DAILY;INTERVAL=2\r\n", 'FREQ=DAILY;INTERVAL=2'];
        yield 'folded with CRLF and space' => ["FREQ=DAILY;INTER\r\n VAL=2", 'FREQ=DAILY;INTERVAL=2'];
        yield 'folded with LF and tab' => ["FREQ=DAILY;\n\tINTERVAL=2", 'FREQ=DAILY;INTERVAL=2'];
        yield 'parts in any order' => ['BYDAY=MO;INTERVAL=2;FREQ=WEEKLY;WKST=SU', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;WKST=SU'];
        yield 'default INTERVAL' => ['FREQ=DAILY;INTERVAL=1', 'FREQ=DAILY'];
        yield 'INTERVAL with leading zeros' => ['FREQ=DAILY;INTERVAL=007', 'FREQ=DAILY;INTERVAL=7'];
        yield 'INTERVAL with 18 digits' => ['FREQ=DAILY;INTERVAL=999999999999999999', 'FREQ=DAILY;INTERVAL=999999999999999999'];
        yield 'INTERVAL equal to PHP_INT_MAX' => ['FREQ=DAILY;INTERVAL=9223372036854775807', 'FREQ=DAILY;INTERVAL=9223372036854775807'];
        yield 'INTERVAL with many leading zeros' => ['FREQ=DAILY;INTERVAL=00000000000000000000002', 'FREQ=DAILY;INTERVAL=2'];
        yield 'COUNT' => ['FREQ=DAILY;COUNT=1', 'FREQ=DAILY;COUNT=1'];
        yield 'COUNT with leading zero' => ['FREQ=DAILY;COUNT=010', 'FREQ=DAILY;COUNT=10'];
        yield 'UNTIL date' => ['FREQ=DAILY;UNTIL=20261231', 'FREQ=DAILY;UNTIL=20261231'];
        yield 'UNTIL floating' => ['FREQ=DAILY;UNTIL=20261231T235959', 'FREQ=DAILY;UNTIL=20261231T235959'];
        yield 'UNTIL UTC' => ['FREQ=DAILY;UNTIL=20261231T235959Z', 'FREQ=DAILY;UNTIL=20261231T235959Z'];
        yield 'UNTIL first year' => ['FREQ=YEARLY;UNTIL=00010101', 'FREQ=YEARLY;UNTIL=00010101'];
        yield 'UNTIL leap day' => ['FREQ=YEARLY;UNTIL=20280229T000000Z', 'FREQ=YEARLY;UNTIL=20280229T000000Z'];
        yield 'BYSECOND bounds' => ['FREQ=MINUTELY;BYSECOND=0,60,01', 'FREQ=MINUTELY;BYSECOND=0,60,1'];
        yield 'BYMINUTE bounds' => ['FREQ=HOURLY;BYMINUTE=0,59', 'FREQ=HOURLY;BYMINUTE=0,59'];
        yield 'BYHOUR bounds' => ['FREQ=DAILY;BYHOUR=0,23', 'FREQ=DAILY;BYHOUR=0,23'];
        yield 'BYDAY all weekdays' => ['FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU', 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR,SA,SU'];
        yield 'BYDAY ordinals' => ['FREQ=YEARLY;BYDAY=+1MO,-53FR,53SA,01TU,-01WE', 'FREQ=YEARLY;BYDAY=1MO,-53FR,53SA,1TU,-1WE'];
        yield 'BYMONTHDAY bounds' => ['FREQ=MONTHLY;BYMONTHDAY=1,31,-1,-31,+5', 'FREQ=MONTHLY;BYMONTHDAY=1,31,-1,-31,5'];
        yield 'BYYEARDAY bounds' => ['FREQ=YEARLY;BYYEARDAY=1,366,-1,-366,+100', 'FREQ=YEARLY;BYYEARDAY=1,366,-1,-366,100'];
        yield 'BYYEARDAY with HOURLY' => ['FREQ=HOURLY;BYYEARDAY=1', 'FREQ=HOURLY;BYYEARDAY=1'];
        yield 'BYWEEKNO bounds' => ['FREQ=YEARLY;BYWEEKNO=1,53,-1,-53,+2', 'FREQ=YEARLY;BYWEEKNO=1,53,-1,-53,2'];
        yield 'BYWEEKNO with BYDAY without ordinal' => ['FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO', 'FREQ=YEARLY;BYDAY=MO;BYWEEKNO=20'];
        yield 'BYMONTH bounds' => ['FREQ=YEARLY;BYMONTH=1,12', 'FREQ=YEARLY;BYMONTH=1,12'];
        yield 'BYMONTH duplicates kept' => ['FREQ=YEARLY;BYMONTH=3,3', 'FREQ=YEARLY;BYMONTH=3,3'];
        yield 'BYSETPOS bounds' => ['FREQ=MONTHLY;BYDAY=MO;BYSETPOS=1,366,-1,-366,+2', 'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=1,366,-1,-366,2'];
        yield 'WKST default' => ['FREQ=WEEKLY;WKST=MO', 'FREQ=WEEKLY'];
        yield 'WKST' => ['FREQ=WEEKLY;WKST=SU', 'FREQ=WEEKLY;WKST=SU'];
        yield 'RSCALE' => ['FREQ=MONTHLY;RSCALE=GREGORIAN', 'FREQ=MONTHLY;RSCALE=GREGORIAN'];
        yield 'RSCALE and SKIP' => ['RSCALE=gregorian;FREQ=YEARLY;SKIP=forward', 'FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=FORWARD'];
        yield 'SKIP=BACKWARD' => ['RSCALE=GREGORIAN;FREQ=MONTHLY;SKIP=BACKWARD', 'FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD'];
        yield 'SKIP=OMIT with RSCALE' => ['RSCALE=GREGORIAN;FREQ=MONTHLY;SKIP=OMIT', 'FREQ=MONTHLY;RSCALE=GREGORIAN'];
    }

    public function testParsedValueTypes(): void
    {
        $parser = new RecurrenceParser();

        $rule = $parser->parseRule('FREQ=MONTHLY;INTERVAL=3;COUNT=5;BYSECOND=1;BYMINUTE=2;BYHOUR=3;BYDAY=-2TU;BYMONTHDAY=-4;BYMONTH=6;BYSETPOS=-7;WKST=WE;RSCALE=GREGORIAN;SKIP=BACKWARD')->value;
        self::assertSame(Frequency::Monthly, $rule->frequency);
        self::assertSame(3, $rule->interval);
        self::assertInstanceOf(Count::class, $rule->end);
        self::assertSame(5, $rule->end->value);
        self::assertSame([1], $rule->bySecond);
        self::assertSame([2], $rule->byMinute);
        self::assertSame([3], $rule->byHour);
        self::assertCount(1, $rule->byDay);
        self::assertTrue($rule->byDay[0]->equals(WeekdayNum::of(Weekday::Tuesday, -2)));
        self::assertSame([-4], $rule->byMonthDay);
        self::assertSame([6], $rule->byMonth);
        self::assertSame([-7], $rule->bySetPos);
        self::assertSame(Weekday::Wednesday, $rule->weekStart);
        self::assertSame(CalendarScale::Gregorian, $rule->calendarScale);
        self::assertSame(Skip::Backward, $rule->skip);

        $yearly = $parser->parseRule('FREQ=YEARLY;BYYEARDAY=-5;BYWEEKNO=-6')->value;
        self::assertSame([-5], $yearly->byYearDay);
        self::assertSame([-6], $yearly->byWeekNo);
        self::assertNull($yearly->end);
        self::assertNull($yearly->calendarScale);

        $until = $parser->parseRule('FREQ=DAILY;UNTIL=20260131')->value->end;
        self::assertInstanceOf(Until::class, $until);
        self::assertInstanceOf(CalendarDate::class, $until->value);
        self::assertSame('2026-01-31', $until->value->toString());

        $until = $parser->parseRule('FREQ=DAILY;UNTIL=20260131T081520')->value->end;
        self::assertInstanceOf(Until::class, $until);
        self::assertInstanceOf(FloatingDateTime::class, $until->value);
        self::assertSame('2026-01-31T08:15:20', $until->value->toString());

        $until = $parser->parseRule('FREQ=DAILY;UNTIL=20260131T081520Z')->value->end;
        self::assertInstanceOf(Until::class, $until);
        self::assertInstanceOf(DateTimeImmutable::class, $until->value);
        self::assertSame('2026-01-31T08:15:20+00:00 UTC', $until->value->format('Y-m-d\TH:i:sP e'));
    }

    /**
     * @param class-string<Throwable>|null $previous
     */
    #[DataProvider('provideInvalidRules')]
    public function testInvalidRule(
        string $input,
        string $message,
        ?string $previous = null,
    ): void
    {
        self::assertRuleError($input, $message, ParseMode::Strict, $previous);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: class-string}>
     */
    public static function provideInvalidRules(): iterable
    {
        yield 'empty' => ['', 'The rule is empty'];
        yield 'whitespace only' => [" \r\n", 'The rule is empty'];
        yield 'empty after RRULE prefix' => ['RRULE:', 'The rule is empty'];
        yield 'two lines' => ["DTSTART:20260101T090000\nRRULE:FREQ=DAILY", 'The rule must be one line; use parseRecurrence() for DTSTART, RDATE and EXDATE lines'];
        yield 'empty part at the end' => ['FREQ=DAILY;', 'The rule "FREQ=DAILY;" contains an empty part'];
        yield 'empty part in the middle' => ['FREQ=DAILY;;COUNT=2', 'The rule "FREQ=DAILY;;COUNT=2" contains an empty part'];
        yield 'part without value' => ['FREQ', 'Invalid rule part "FREQ": expected NAME=VALUE'];
        yield 'part with space in the name' => ['FREQ =DAILY', 'Invalid rule part "FREQ =DAILY": the name is not valid'];
        yield 'part with space before the name' => ['FREQ=DAILY; COUNT=2', 'Invalid rule part " COUNT=2": the name is not valid'];
        yield 'part without name' => ['=DAILY', 'Invalid rule part "=DAILY": the name is not valid'];
        yield 'unknown part' => ['FREQ=DAILY;FOO=1', 'Unknown rule part "FOO=1"'];
        yield 'X- part' => ['FREQ=DAILY;X-NAME=a=b', 'Unknown rule part "X-NAME=a=b"'];
        yield 'DTSTART part' => ['DTSTART=20260101T090000;FREQ=DAILY', 'Unknown rule part "DTSTART=20260101T090000"'];
        yield 'duplicate part' => ['FREQ=DAILY;FREQ=WEEKLY', 'The rule part FREQ occurs more than once'];
        yield 'duplicate part in other case' => ['FREQ=DAILY;BYHOUR=1;byhour=1', 'The rule part BYHOUR occurs more than once'];
        yield 'empty value' => ['FREQ=', 'The rule part FREQ has an empty value'];
        yield 'missing FREQ' => ['INTERVAL=2', 'Invalid rule "INTERVAL=2": FREQ is required'];
        yield 'unknown FREQ' => ['FREQ=FORTNIGHTLY', 'Invalid FREQ value "FORTNIGHTLY" (allowed: SECONDLY, MINUTELY, HOURLY, DAILY, WEEKLY, MONTHLY, YEARLY)'];
        yield 'INTERVAL zero' => ['FREQ=DAILY;INTERVAL=0', 'Invalid INTERVAL value "0": INTERVAL must be a positive integer'];
        yield 'INTERVAL negative' => ['FREQ=DAILY;INTERVAL=-1', 'Invalid INTERVAL value "-1": a sign is not allowed'];
        yield 'INTERVAL with plus' => ['FREQ=DAILY;INTERVAL=+1', 'Invalid INTERVAL value "+1": a sign is not allowed'];
        yield 'INTERVAL decimal' => ['FREQ=DAILY;INTERVAL=1.5', 'Invalid INTERVAL value "1.5": expected an integer'];
        yield 'INTERVAL text' => ['FREQ=DAILY;INTERVAL=two', 'Invalid INTERVAL value "two": expected an integer'];
        yield 'INTERVAL with trailing text' => ['FREQ=DAILY;INTERVAL=2x', 'Invalid INTERVAL value "2x": expected an integer'];
        yield 'INTERVAL with leading text' => ['FREQ=DAILY;INTERVAL=x2', 'Invalid INTERVAL value "x2": expected an integer'];
        yield 'INTERVAL above PHP_INT_MAX' => ['FREQ=DAILY;INTERVAL=9223372036854775808', 'Invalid INTERVAL value "9223372036854775808": the number is larger than 9223372036854775807'];
        yield 'INTERVAL with 20 digits' => ['FREQ=DAILY;INTERVAL=10000000000000000000', 'Invalid INTERVAL value "10000000000000000000": the number is larger than 9223372036854775807'];
        yield 'COUNT zero' => ['FREQ=DAILY;COUNT=0', 'Invalid COUNT value "0": COUNT must be a positive integer, 0 given', InvalidValueException::class];
        yield 'COUNT negative' => ['FREQ=DAILY;COUNT=-1', 'Invalid COUNT value "-1": COUNT must be a positive integer, -1 given', InvalidValueException::class];
        yield 'COUNT with plus' => ['FREQ=DAILY;COUNT=+1', 'Invalid COUNT value "+1": a sign is not allowed'];
        yield 'COUNT and UNTIL' => ['FREQ=DAILY;COUNT=2;UNTIL=20260101', 'Invalid rule "FREQ=DAILY;COUNT=2;UNTIL=20260101": COUNT and UNTIL must not occur in the same rule (RFC 5545 section 3.3.10)'];
        yield 'UNTIL short' => ['FREQ=DAILY;UNTIL=2026', 'Invalid UNTIL value "2026": expected YYYYMMDD, YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ'];
        yield 'UNTIL without seconds' => ['FREQ=DAILY;UNTIL=20260101T0900', 'Invalid UNTIL value "20260101T0900": expected YYYYMMDD, YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ'];
        yield 'UNTIL with offset' => ['FREQ=DAILY;UNTIL=20260101T090000+0100', 'Invalid UNTIL value "20260101T090000+0100": expected YYYYMMDD, YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ'];
        yield 'UNTIL with leading text' => ['FREQ=DAILY;UNTIL=x20260101', 'Invalid UNTIL value "x20260101": expected YYYYMMDD, YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ'];
        yield 'UNTIL date-time with leading text' => ['FREQ=DAILY;UNTIL=x20260101T090000', 'Invalid UNTIL value "x20260101T090000": expected YYYYMMDD, YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ'];
        yield 'UNTIL February 30' => ['FREQ=DAILY;UNTIL=20260230', 'Invalid UNTIL value "20260230": Invalid date 2026-02-30 (years 1 to 9999 are supported)', InvalidValueException::class];
        yield 'UNTIL February 29 in a common year' => ['FREQ=DAILY;UNTIL=20270229T000000Z', 'Invalid UNTIL value "20270229T000000Z": Invalid date-time 2027-02-29T00:00:00 (years 1 to 9999 are supported)', InvalidValueException::class];
        yield 'UNTIL month 13' => ['FREQ=DAILY;UNTIL=20261301', 'Invalid UNTIL value "20261301": Invalid date 2026-13-01 (years 1 to 9999 are supported)', InvalidValueException::class];
        yield 'UNTIL year 0' => ['FREQ=DAILY;UNTIL=00001231', 'Invalid UNTIL value "00001231": Invalid date 0000-12-31 (years 1 to 9999 are supported)', InvalidValueException::class];
        yield 'UNTIL hour 24' => ['FREQ=DAILY;UNTIL=20260101T240000', 'Invalid UNTIL value "20260101T240000": Invalid date-time 2026-01-01T24:00:00 (years 1 to 9999 are supported)', InvalidValueException::class];
        yield 'UNTIL minute 60' => ['FREQ=DAILY;UNTIL=20260101T126000Z', 'Invalid UNTIL value "20260101T126000Z": Invalid date-time 2026-01-01T12:60:00 (years 1 to 9999 are supported)', InvalidValueException::class];
        yield 'UNTIL second 60' => ['FREQ=DAILY;UNTIL=20260101T120060', 'Invalid UNTIL value "20260101T120060": Invalid date-time 2026-01-01T12:00:60 (years 1 to 9999 are supported)', InvalidValueException::class];
        yield 'BYSECOND 61' => ['FREQ=DAILY;BYSECOND=61', 'Invalid BYSECOND value "61": allowed values are 0 to 60'];
        yield 'BYSECOND negative' => ['FREQ=DAILY;BYSECOND=-1', 'Invalid BYSECOND value "-1": a sign is not allowed'];
        yield 'BYSECOND with plus' => ['FREQ=DAILY;BYSECOND=+1', 'Invalid BYSECOND value "+1": a sign is not allowed'];
        yield 'BYSECOND empty item' => ['FREQ=DAILY;BYSECOND=1,,2', 'Invalid BYSECOND value "1,,2": the list contains an empty item'];
        yield 'BYSECOND trailing comma' => ['FREQ=DAILY;BYSECOND=1,', 'Invalid BYSECOND value "1,": the list contains an empty item'];
        yield 'BYSECOND huge' => ['FREQ=DAILY;BYSECOND=99999999999999999999', 'Invalid BYSECOND value "99999999999999999999": the number is larger than 9223372036854775807'];
        yield 'BYMINUTE 60' => ['FREQ=DAILY;BYMINUTE=60', 'Invalid BYMINUTE value "60": allowed values are 0 to 59'];
        yield 'BYHOUR 24' => ['FREQ=DAILY;BYHOUR=24', 'Invalid BYHOUR value "24": allowed values are 0 to 23'];
        yield 'BYMONTH 0' => ['FREQ=YEARLY;BYMONTH=0', 'Invalid BYMONTH value "0": allowed values are 1 to 12'];
        yield 'BYMONTH 13' => ['FREQ=YEARLY;BYMONTH=13', 'Invalid BYMONTH value "13": allowed values are 1 to 12'];
        yield 'BYMONTH leap month' => ['RSCALE=GREGORIAN;FREQ=YEARLY;BYMONTH=5L', 'Invalid BYMONTH value "5L": leap months (RFC 7529) do not exist in the GREGORIAN calendar'];
        yield 'BYMONTH leap month in a list' => ['FREQ=YEARLY;BYMONTH=1,5l,7', 'Invalid BYMONTH value "1,5l,7": leap months (RFC 7529) do not exist in the GREGORIAN calendar'];
        yield 'BYMONTH leap month last' => ['FREQ=YEARLY;BYMONTH=1,12L', 'Invalid BYMONTH value "1,12L": leap months (RFC 7529) do not exist in the GREGORIAN calendar'];
        yield 'BYMONTH with letter only' => ['FREQ=YEARLY;BYMONTH=L', 'Invalid BYMONTH value "L": expected an integer'];
        yield 'BYMONTH with L inside' => ['FREQ=YEARLY;BYMONTH=1L2', 'Invalid BYMONTH value "1L2": expected an integer'];
        yield 'BYMONTHDAY 0' => ['FREQ=MONTHLY;BYMONTHDAY=0', 'Invalid BYMONTHDAY value "0": allowed values are -31 to -1 and 1 to 31'];
        yield 'BYMONTHDAY 32' => ['FREQ=MONTHLY;BYMONTHDAY=32', 'Invalid BYMONTHDAY value "32": allowed values are -31 to -1 and 1 to 31'];
        yield 'BYMONTHDAY -32' => ['FREQ=MONTHLY;BYMONTHDAY=-32', 'Invalid BYMONTHDAY value "-32": allowed values are -31 to -1 and 1 to 31'];
        yield 'BYMONTHDAY -0' => ['FREQ=MONTHLY;BYMONTHDAY=-0', 'Invalid BYMONTHDAY value "-0": allowed values are -31 to -1 and 1 to 31'];
        yield 'BYYEARDAY 367' => ['FREQ=YEARLY;BYYEARDAY=367', 'Invalid BYYEARDAY value "367": allowed values are -366 to -1 and 1 to 366'];
        yield 'BYYEARDAY -367' => ['FREQ=YEARLY;BYYEARDAY=-367', 'Invalid BYYEARDAY value "-367": allowed values are -366 to -1 and 1 to 366'];
        yield 'BYWEEKNO 54' => ['FREQ=YEARLY;BYWEEKNO=54', 'Invalid BYWEEKNO value "54": allowed values are -53 to -1 and 1 to 53'];
        yield 'BYWEEKNO 0' => ['FREQ=YEARLY;BYWEEKNO=0', 'Invalid BYWEEKNO value "0": allowed values are -53 to -1 and 1 to 53'];
        yield 'BYSETPOS 367' => ['FREQ=MONTHLY;BYDAY=MO;BYSETPOS=367', 'Invalid BYSETPOS value "367": allowed values are -366 to -1 and 1 to 366'];
        yield 'BYSETPOS empty item' => ['FREQ=MONTHLY;BYDAY=MO;BYSETPOS=,1', 'Invalid BYSETPOS value ",1": the list contains an empty item'];
        yield 'BYDAY unknown weekday' => ['FREQ=WEEKLY;BYDAY=XX', 'Invalid BYDAY value "XX": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal'];
        yield 'BYDAY three letters' => ['FREQ=WEEKLY;BYDAY=MON', 'Invalid BYDAY value "MON": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal'];
        yield 'BYDAY ordinal only' => ['FREQ=MONTHLY;BYDAY=1', 'Invalid BYDAY value "1": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal'];
        yield 'BYDAY sign only' => ['FREQ=MONTHLY;BYDAY=+MO', 'Invalid BYDAY value "+MO": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal'];
        yield 'BYDAY with space' => ['FREQ=MONTHLY;BYDAY=1 MO', 'Invalid BYDAY value "1 MO": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal'];
        yield 'BYDAY with trailing text' => ['FREQ=MONTHLY;BYDAY=MOX', 'Invalid BYDAY value "MOX": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal'];
        yield 'BYDAY with leading text' => ['FREQ=MONTHLY;BYDAY=X1MO', 'Invalid BYDAY value "X1MO": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal'];
        yield 'BYDAY ordinal 54' => ['FREQ=MONTHLY;BYDAY=54MO', 'Invalid BYDAY value "54MO": Invalid BYDAY ordinal 54 (allowed: -53 to -1 and 1 to 53)', InvalidValueException::class];
        yield 'BYDAY ordinal 0' => ['FREQ=MONTHLY;BYDAY=0MO', 'Invalid BYDAY value "0MO": Invalid BYDAY ordinal 0 (allowed: -53 to -1 and 1 to 53)', InvalidValueException::class];
        yield 'BYDAY huge ordinal' => ['FREQ=MONTHLY;BYDAY=99999999999999999999MO', 'Invalid BYDAY value "99999999999999999999MO": the number is larger than 9223372036854775807'];
        yield 'BYDAY empty item' => ['FREQ=WEEKLY;BYDAY=MO,', 'Invalid BYDAY value "MO,": the list contains an empty item'];
        yield 'WKST unknown' => ['FREQ=WEEKLY;WKST=XX', 'Invalid WKST value "XX" (allowed: MO, TU, WE, TH, FR, SA, SU)'];
        yield 'WKST with ordinal' => ['FREQ=WEEKLY;WKST=1MO', 'Invalid WKST value "1MO" (allowed: MO, TU, WE, TH, FR, SA, SU)'];
        yield 'RSCALE other calendar' => ['RSCALE=HEBREW;FREQ=YEARLY', 'Invalid RSCALE value "HEBREW": the calendar scale is not supported (only GREGORIAN is)'];
        yield 'SKIP unknown' => ['RSCALE=GREGORIAN;FREQ=YEARLY;SKIP=SOMETIMES', 'Invalid SKIP value "SOMETIMES" (allowed: OMIT, BACKWARD, FORWARD)'];
        yield 'SKIP without RSCALE' => ['FREQ=YEARLY;SKIP=FORWARD', 'Invalid rule "FREQ=YEARLY;SKIP=FORWARD": SKIP requires RSCALE (RFC 7529 section 4.1)'];
        yield 'SKIP=OMIT without RSCALE' => ['FREQ=YEARLY;SKIP=OMIT', 'Invalid rule "FREQ=YEARLY;SKIP=OMIT": SKIP requires RSCALE (RFC 7529 section 4.1)'];
        yield 'BYWEEKNO with MONTHLY' => ['FREQ=MONTHLY;BYWEEKNO=1', 'Invalid rule "FREQ=MONTHLY;BYWEEKNO=1": BYWEEKNO is allowed only with FREQ=YEARLY', InvalidRecurrenceRuleException::class];
        yield 'BYYEARDAY with DAILY' => ['FREQ=DAILY;BYYEARDAY=1', 'Invalid rule "FREQ=DAILY;BYYEARDAY=1": BYYEARDAY is not allowed with FREQ=DAILY', InvalidRecurrenceRuleException::class];
        yield 'BYYEARDAY with WEEKLY' => ['FREQ=WEEKLY;BYYEARDAY=1', 'Invalid rule "FREQ=WEEKLY;BYYEARDAY=1": BYYEARDAY is not allowed with FREQ=WEEKLY', InvalidRecurrenceRuleException::class];
        yield 'BYYEARDAY with MONTHLY' => ['FREQ=MONTHLY;BYYEARDAY=1', 'Invalid rule "FREQ=MONTHLY;BYYEARDAY=1": BYYEARDAY is not allowed with FREQ=MONTHLY', InvalidRecurrenceRuleException::class];
        yield 'BYMONTHDAY with WEEKLY' => ['FREQ=WEEKLY;BYMONTHDAY=1', 'Invalid rule "FREQ=WEEKLY;BYMONTHDAY=1": BYMONTHDAY is not allowed with FREQ=WEEKLY', InvalidRecurrenceRuleException::class];
        yield 'BYDAY ordinal with WEEKLY' => ['FREQ=WEEKLY;BYDAY=1MO', 'Invalid rule "FREQ=WEEKLY;BYDAY=1MO": BYDAY value 1MO has an ordinal, which is allowed only with FREQ=MONTHLY or FREQ=YEARLY', InvalidRecurrenceRuleException::class];
        yield 'BYDAY ordinal with BYWEEKNO' => ['FREQ=YEARLY;BYWEEKNO=1;BYDAY=-1MO', 'Invalid rule "FREQ=YEARLY;BYWEEKNO=1;BYDAY=-1MO": BYDAY value -1MO has an ordinal, which is not allowed together with BYWEEKNO', InvalidRecurrenceRuleException::class];
        yield 'BYSETPOS alone' => ['FREQ=MONTHLY;BYSETPOS=1', 'Invalid rule "FREQ=MONTHLY;BYSETPOS=1": BYSETPOS must be used together with another BYxxx rule part', InvalidRecurrenceRuleException::class];
    }

    public function testErrorsInLenientMode(): void
    {
        self::assertRuleError('FREQ=DAILY;COUNT=+1', 'Invalid COUNT value "+1": a sign is not allowed', ParseMode::Lenient);
        self::assertRuleError('FREQ =DAILY', 'Invalid rule part "FREQ =DAILY": the name is not valid', ParseMode::Lenient);
        self::assertRuleError('FREQ=DAILY;COUNT=', 'The rule part COUNT has an empty value', ParseMode::Lenient);
        self::assertRuleError(';', 'Invalid rule ";": FREQ is required', ParseMode::Lenient);
        self::assertRuleError('FREQ=DAILY;BYHOUR=25', 'Invalid BYHOUR value "25": allowed values are 0 to 23', ParseMode::Lenient);
        self::assertRuleError('FREQ=DAILY;INTERVAL=0', 'Invalid INTERVAL value "0": INTERVAL must be a positive integer', ParseMode::Lenient);
    }

}
