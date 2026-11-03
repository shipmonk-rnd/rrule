<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Parser;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\InvalidRecurrenceRuleException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\Parser\ContentLine;
use ShipMonk\RRule\Internal\Parser\ContentLineReader;
use ShipMonk\RRule\Internal\Parser\ParseContext;
use ShipMonk\RRule\Internal\Parser\ParseErrors;
use ShipMonk\RRule\Internal\Parser\RawRule;
use ShipMonk\RRule\Internal\Parser\RecurrenceTextParser;
use ShipMonk\RRule\Internal\Parser\RuleParser;
use ShipMonk\RRule\Internal\Parser\ValueParser;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\ParseResult;
use ShipMonk\RRule\Parser\ParseWarning;
use ShipMonk\RRule\Parser\ParseWarningCode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use ShipMonk\RRule\RecurrenceRule;
use Throwable;
use function array_map;

#[CoversClass(RecurrenceParser::class)]
#[CoversClass(ParseResult::class)]
#[CoversClass(ParseWarning::class)]
#[CoversClass(RecurrenceTextParser::class)]
#[CoversClass(RuleParser::class)]
#[CoversClass(RawRule::class)]
#[CoversClass(ValueParser::class)]
#[CoversClass(ParseContext::class)]
#[CoversClass(ParseErrors::class)]
#[CoversClass(ContentLine::class)]
#[CoversClass(ContentLineReader::class)]
final class RecurrenceParsingTest extends ParserTestCase
{

    #[DataProvider('provideStarts')]
    public function testStart(
        string $input,
        string $expected,
    ): void
    {
        foreach ([ParseMode::Strict, ParseMode::Lenient] as $mode) {
            $result = (new RecurrenceParser($mode))->parseRecurrence($input);

            self::assertSame($expected, self::describe($result->value->getStart()));
            self::assertSame([], $result->value->getRules());
            self::assertSame([], $result->warnings);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideStarts(): iterable
    {
        yield 'date' => ['DTSTART;VALUE=DATE:20260131', '2026-01-31 date'];
        yield 'date with lower-case VALUE' => ['dtstart;value=date:20260131', '2026-01-31 date'];
        yield 'first date' => ['DTSTART;VALUE=DATE:00010101', '0001-01-01 date'];
        yield 'floating' => ['DTSTART:20260131T090000', '2026-01-31T09:00:00 floating'];
        yield 'floating with explicit VALUE' => ['DTSTART;VALUE=DATE-TIME:20260131T090000', '2026-01-31T09:00:00 floating'];
        yield 'last floating' => ['DTSTART:99991231T235959', '9999-12-31T23:59:59 floating'];
        yield 'UTC' => ['DTSTART:20260131T090000Z', '2026-01-31T09:00:00+00:00 UTC'];
        yield 'UTC in lower case' => ['dtstart:20260131t090000z', '2026-01-31T09:00:00+00:00 UTC'];
        yield 'zoned' => ['DTSTART;TZID=Europe/Prague:20260131T090000', '2026-01-31T09:00:00+01:00 Europe/Prague'];
        yield 'zoned with quoted TZID' => ['DTSTART;TZID="Europe/Prague":20260131T090000', '2026-01-31T09:00:00+01:00 Europe/Prague'];
        yield 'zoned with lower-case names' => ['dtstart;tzid=America/New_York;value=date-time:19970902T090000', '1997-09-02T09:00:00-04:00 America/New_York'];
        yield 'zoned in a DST gap' => ['DTSTART;TZID=Europe/Prague:20260329T023000', '2026-03-29T03:30:00+02:00 Europe/Prague'];
        yield 'zoned in a DST overlap' => ['DTSTART;TZID=Europe/Prague:20261025T023000', '2026-10-25T02:30:00+02:00 Europe/Prague'];
        yield 'TZID=UTC' => ['DTSTART;TZID=UTC:20260131T090000', '2026-01-31T09:00:00+00:00 UTC'];
        yield 'unknown parameters are ignored' => ['DTSTART;X-A=1;X-B="x:y;z",b;VALUE=DATE:20260131', '2026-01-31 date'];
        yield 'empty parameter values' => ['DTSTART;X-A=;X-B="":20260131T090000', '2026-01-31T09:00:00 floating'];
        yield 'trailing line break' => ["DTSTART:20260131T090000\r\n\r\n", '2026-01-31T09:00:00 floating'];
        yield 'trailing whitespace' => ["DTSTART:20260131T090000 \t\r\n ", '2026-01-31T09:00:00 floating'];
        yield 'leading empty lines' => ["\r\n\nDTSTART:20260131T090000", '2026-01-31T09:00:00 floating'];
        yield 'folded parameter' => ["DTSTART;TZID=Europe/Pr\r\n ague:20260131T090000", '2026-01-31T09:00:00+01:00 Europe/Prague'];
        yield 'folded with tab' => ["DTSTART:2026\n\t0131T090000", '2026-01-31T09:00:00 floating'];
    }

    public function testZonedStartKeepsTheWrittenTime(): void
    {
        $recurrence = (new RecurrenceParser())->parseRecurrence("DTSTART;TZID=America/New_York:20260308T023000\nRRULE:FREQ=DAILY;COUNT=3")->value;
        $occurrences = [];

        foreach ($recurrence as $occurrence) {
            self::assertInstanceOf(DateTimeImmutable::class, $occurrence);
            $occurrences[] = $occurrence->format('c');
        }

        self::assertSame(['2026-03-08T03:30:00-04:00', '2026-03-09T02:30:00-04:00', '2026-03-10T02:30:00-04:00'], $occurrences);
        self::assertSame("DTSTART;TZID=America/New_York:20260308T023000\nRRULE:FREQ=DAILY;COUNT=3", $recurrence->toString("\n"));

        $utc = (new RecurrenceParser())->parseRecurrence("DTSTART:20260308T023000Z\nRRULE:FREQ=DAILY;COUNT=1")->value;
        self::assertSame('UTC', $utc->getStart() instanceof DateTimeImmutable ? $utc->getStart()->getTimezone()->getName() : null);
    }

    public function testParseExceptionDetails(): void
    {
        try {
            (new RecurrenceParser())->parseRecurrence("DTSTART:20260101T000000\nRRULE:FREQ=SOMETIMES");
            self::fail('ParseException expected');
        } catch (ParseException $e) {
            self::assertSame(0, $e->getCode());
            self::assertSame(2, $e->lineNumber);
        }
    }

    public function testFullRecurrence(): void
    {
        $input = "DTSTART;TZID=Europe/Prague:20260105T090000\r\n"
            . "RRULE:FREQ=WEEKLY;BYDAY=MO\r\n"
            . "rrule;X-PARAM=1:FREQ=MONTHLY;BYMONTHDAY=15\r\n"
            . "\r\n"
            . "RDATE;TZID=Europe/Prague:20260107T100000,20260108T110000\r\n"
            . "RDATE:20260109T080000Z\r\n"
            . "RDATE;TZID=America/New_York:20260110T090000\r\n"
            . "EXDATE;TZID=Europe/Prague:20260112T090000\r\n"
            . "EXDATE:20260119T08\r\n 0000Z\r\n";

        $result = (new RecurrenceParser())->parseRecurrence($input);
        $recurrence = $result->value;

        self::assertSame([], $result->warnings);
        self::assertSame('2026-01-05T09:00:00+01:00 Europe/Prague', self::describe($recurrence->getStart()));
        self::assertSame(['FREQ=WEEKLY;BYDAY=MO', 'FREQ=MONTHLY;BYMONTHDAY=15'], array_map(static fn (RecurrenceRule $rule): string => $rule->toString(), $recurrence->getRules()));
        self::assertSame(
            [
                '2026-01-07T10:00:00+01:00 Europe/Prague',
                '2026-01-08T11:00:00+01:00 Europe/Prague',
                '2026-01-09T09:00:00+01:00 Europe/Prague',
                '2026-01-10T15:00:00+01:00 Europe/Prague',
            ],
            array_map(self::describe(...), $recurrence->getAddedDates()),
        );
        self::assertSame(
            ['2026-01-12T09:00:00+01:00 Europe/Prague', '2026-01-19T09:00:00+01:00 Europe/Prague'],
            array_map(self::describe(...), $recurrence->getExcludedDates()),
        );
        self::assertSame([], $recurrence->getExcludedDays());
        self::assertSame([], $recurrence->getExclusionRules());
    }

    public function testDateRecurrence(): void
    {
        $input = "DTSTART;VALUE=DATE:20260101\nRRULE:FREQ=DAILY;COUNT=10\nRDATE;VALUE=DATE:20260201,20260301\nEXDATE;VALUE=DATE:20260102";

        $result = (new RecurrenceParser())->parseRecurrence($input);
        $recurrence = $result->value;

        self::assertSame([], $result->warnings);
        self::assertSame(['2026-02-01 date', '2026-03-01 date'], array_map(self::describe(...), $recurrence->getAddedDates()));
        self::assertSame(['2026-01-02 date'], array_map(self::describe(...), $recurrence->getExcludedDates()));
        self::assertSame([], $recurrence->getExcludedDays());
    }

    public function testFloatingRecurrence(): void
    {
        $input = "RDATE:20260201T100000\nDTSTART:20260101T090000\nEXDATE:20260102T090000,20260103T090000";

        $recurrence = (new RecurrenceParser())->parseRecurrence($input)->value;

        self::assertSame('2026-01-01T09:00:00 floating', self::describe($recurrence->getStart()));
        self::assertSame(['2026-02-01T10:00:00 floating'], array_map(self::describe(...), $recurrence->getAddedDates()));
        self::assertSame(['2026-01-02T09:00:00 floating', '2026-01-03T09:00:00 floating'], array_map(self::describe(...), $recurrence->getExcludedDates()));
    }

    public function testUtcRecurrence(): void
    {
        $input = "DTSTART:20260101T090000Z\nRDATE;TZID=Europe/Prague:20260201T100000\nEXDATE:20260102T090000Z";

        $recurrence = (new RecurrenceParser())->parseRecurrence($input)->value;

        self::assertSame(['2026-02-01T09:00:00+00:00 UTC'], array_map(self::describe(...), $recurrence->getAddedDates()));
        self::assertSame(['2026-01-02T09:00:00+00:00 UTC'], array_map(self::describe(...), $recurrence->getExcludedDates()));
    }

    public function testPeriods(): void
    {
        $input = "DTSTART:20260101T090000Z\n"
            . "RDATE;VALUE=PERIOD:20260110T090000Z/20260110T100000Z,20260111T090000Z/PT1H\n"
            . 'RDATE;VALUE=period;TZID=Europe/Prague:20260112T090000/20260112T100000';

        foreach ([ParseMode::Strict, ParseMode::Lenient] as $mode) {
            $result = (new RecurrenceParser($mode))->parseRecurrence($input);

            self::assertSame(
                ['2026-01-10T09:00:00+00:00 UTC', '2026-01-11T09:00:00+00:00 UTC', '2026-01-12T08:00:00+00:00 UTC'],
                array_map(self::describe(...), $result->value->getAddedDates()),
            );
            self::assertWarnings(
                [
                    [ParseWarningCode::PeriodEndIgnored, 'Ignored the end of each RDATE period: only the period start is used', 2],
                    [ParseWarningCode::PeriodEndIgnored, 'Ignored the end of each RDATE period: only the period start is used', 3],
                ],
                $result->warnings,
            );
        }
    }

    #[DataProvider('provideValidPeriodEnds')]
    public function testValidPeriodEnd(string $end): void
    {
        $result = (new RecurrenceParser())->parseRecurrence("DTSTART:20260101T090000\nRDATE;VALUE=PERIOD:20260110T090000/" . $end);

        self::assertSame(['2026-01-10T09:00:00 floating'], array_map(self::describe(...), $result->value->getAddedDates()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidPeriodEnds(): iterable
    {
        yield 'floating end' => ['20260110T100000'];
        yield 'weeks' => ['P2W'];
        yield 'days' => ['P1D'];
        yield 'days and hours' => ['P1DT2H'];
        yield 'days, hours, minutes, seconds' => ['P10DT2H30M15S'];
        yield 'hours' => ['PT1H'];
        yield 'hours and minutes' => ['PT1H30M'];
        yield 'minutes' => ['PT30M'];
        yield 'minutes and seconds' => ['PT30M15S'];
        yield 'seconds' => ['PT15S'];
        yield 'plus sign' => ['+P1D'];
        yield 'lower case' => ['p1dt2h'];
    }

    #[DataProvider('provideInvalidPeriodEnds')]
    public function testInvalidPeriodEnd(string $end): void
    {
        self::assertRecurrenceError(
            "DTSTART:20260101T090000\nRDATE;VALUE=PERIOD:20260110T090000/" . $end,
            'Line 2: Invalid PERIOD end value "' . $end . '": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)',
            2,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidPeriodEnds(): iterable
    {
        yield 'empty' => [''];
        yield 'P only' => ['P'];
        yield 'PT only' => ['PT'];
        yield 'hours without T' => ['P1H'];
        yield 'negative' => ['-P1D'];
        yield 'weeks and days' => ['P1W1D'];
        yield 'seconds before minutes' => ['PT1S1M'];
        yield 'T without time' => ['P1DT'];
        yield 'hours and seconds without minutes' => ['PT1H15S'];
        yield 'leading text' => ['xP1D'];
        yield 'trailing text' => ['P1Dx'];
        yield 'date' => ['20260110'];
    }

    public function testDateExclusionForDateTimeStartInStrictMode(): void
    {
        $result = (new RecurrenceParser())->parseRecurrence("DTSTART;TZID=Europe/Prague:20260105T090000\nEXDATE;VALUE=DATE:20260112,20260119");

        self::assertSame([], $result->value->getExcludedDates());
        self::assertSame(['2026-01-12 date', '2026-01-19 date'], array_map(self::describe(...), $result->value->getExcludedDays()));
        self::assertWarnings(
            [[ParseWarningCode::DateExclusionForDateTimeStart, 'EXDATE;VALUE=DATE with a DATE-TIME start excludes every occurrence on these dates', 2]],
            $result->warnings,
        );
    }

    /**
     * @param class-string<Throwable>|null $previous
     */
    #[DataProvider('provideInvalidRecurrences')]
    public function testInvalidRecurrence(
        string $input,
        string $message,
        ?int $lineNumber,
        ?string $previous = null,
    ): void
    {
        self::assertRecurrenceError($input, $message, $lineNumber, ParseMode::Strict, $previous);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int|null, 3?: class-string}>
     */
    public static function provideInvalidRecurrences(): iterable
    {
        $start = "DTSTART:20260101T090000\n";
        $dateStart = "DTSTART;VALUE=DATE:20260101\n";
        $zonedStart = "DTSTART;TZID=Europe/Prague:20260101T090000\n";

        yield 'empty text' => ['', 'DTSTART is required', null];
        yield 'missing DTSTART' => ['RRULE:FREQ=DAILY', 'DTSTART is required', null];
        yield 'duplicate DTSTART' => [$start . "RRULE:FREQ=DAILY\ndtstart:20260101T090000", 'Line 3: DTSTART occurs more than once (first on line 1)', 3];
        yield 'EXRULE' => [$start . 'EXRULE:FREQ=DAILY', 'Line 2: EXRULE is not allowed, because RFC 5545 removed it', 2];
        yield 'unknown property' => [$start . 'SUMMARY:Meeting', 'Line 2: Unknown property SUMMARY (allowed: DTSTART, RRULE, RDATE, EXDATE)', 2];
        yield 'component' => ["BEGIN:VEVENT\n" . $start, 'Line 1: Unknown property BEGIN (allowed: DTSTART, RRULE, RDATE, EXDATE)', 1];
        yield 'X- property' => [$start . 'x-wr-timezone:Europe/Prague', 'Line 2: Unknown property X-WR-TIMEZONE (allowed: DTSTART, RRULE, RDATE, EXDATE)', 2];

        yield 'line starting with a space' => [' DTSTART:20260101T090000', 'Line 1: " DTSTART:20260101T090000" does not start with a property name', 1];
        yield 'line starting with a colon' => [':20260101T090000', 'Line 1: ":20260101T090000" does not start with a property name', 1];
        yield 'parameter without name' => ['DTSTART;=x:20260101T090000', 'Line 1: Invalid parameter in "DTSTART;=x:20260101T090000"', 1];
        yield 'parameter without value' => ['DTSTART;TZID:20260101T090000', 'Line 1: Invalid parameter in "DTSTART;TZID:20260101T090000"', 1];
        yield 'empty parameter' => ['DTSTART;:20260101T090000', 'Line 1: Invalid parameter in "DTSTART;:20260101T090000"', 1];
        yield 'unclosed quote' => ['DTSTART;TZID="Europe/Prague:20260101T090000', 'Line 1: A quoted parameter value is not closed in "DTSTART;TZID="Europe/Prague:20260101T090000"', 1];
        yield 'text after quote' => ['DTSTART;TZID="Europe/Prague"x:20260101T090000', 'Line 1: ":" is missing after the property name and parameters in "DTSTART;TZID="Europe/Prague"x:20260101T090000"', 1];
        yield 'quote inside a parameter value' => ['DTSTART;TZID=Europe"/Prague":20260101T090000', 'Line 1: ":" is missing after the property name and parameters in "DTSTART;TZID=Europe"/Prague":20260101T090000"', 1];
        yield 'name only' => ['DTSTART', 'Line 1: ":" is missing after the property name and parameters in "DTSTART"', 1];
        yield 'space after name' => ['DTSTART 20260101T090000', 'Line 1: ":" is missing after the property name and parameters in "DTSTART 20260101T090000"', 1];
        yield 'parameter without colon' => ['DTSTART;VALUE=DATE', 'Line 1: ":" is missing after the property name and parameters in "DTSTART;VALUE=DATE"', 1];
        yield 'control character' => ["DTSTART:20260101T090000\x01", 'Line 1: The line contains a control character', 1];
        yield 'NUL in a parameter' => ["DTSTART;TZID=Europe/Prague\x00:20260101T090000", 'Line 1: The line contains a control character', 1];
        yield 'DEL character' => ["DTSTART:20260101T090000\x7F", 'Line 1: The line contains a control character', 1];
        yield 'lone CR' => ["DTSTART:20260101T090000\rRRULE:FREQ=DAILY", 'Line 1: The line contains a control character', 1];
        yield 'two TZID values' => ['DTSTART;TZID=Europe/Prague,Europe/Berlin:20260101T090000', 'Line 1: The TZID parameter of DTSTART must have exactly one value', 1];
        yield 'two TZID parameters' => ['DTSTART;TZID=Europe/Prague;tzid=Europe/Prague:20260101T090000', 'Line 1: The TZID parameter of DTSTART must have exactly one value', 1];
        yield 'two VALUE parameters' => ['DTSTART;VALUE=DATE;VALUE=DATE:20260101', 'Line 1: The VALUE parameter of DTSTART must have exactly one value', 1];

        yield 'VALUE=TEXT' => ['DTSTART;VALUE=TEXT:20260101', 'Line 1: VALUE=TEXT is not allowed for DTSTART (allowed: DATE-TIME, DATE)', 1];
        yield 'DTSTART with VALUE=PERIOD' => ['DTSTART;VALUE=period:20260101T090000/PT1H', 'Line 1: VALUE=period is not allowed for DTSTART (allowed: DATE-TIME, DATE)', 1];
        yield 'EXDATE with VALUE=PERIOD' => [$start . 'EXDATE;VALUE=PERIOD:20260101T090000/PT1H', 'Line 2: VALUE=PERIOD is not allowed for EXDATE (allowed: DATE-TIME, DATE)', 2];
        yield 'RDATE with VALUE=TEXT' => [$start . 'RDATE;VALUE=TEXT:x', 'Line 2: VALUE=TEXT is not allowed for RDATE (allowed: DATE-TIME, DATE, PERIOD)', 2];
        yield 'TZID with VALUE=DATE' => ['DTSTART;VALUE=DATE;TZID=Europe/Prague:20260101', 'Line 1: TZID must not be used with VALUE=DATE (RFC 5545 section 3.2.19) in DTSTART', 1];
        yield 'VALUE=DATE with a time' => ['DTSTART;VALUE=DATE:20260101T090000', 'Line 1: Invalid DTSTART value "20260101T090000": VALUE=DATE does not allow a time', 1];
        yield 'date without VALUE=DATE' => ['DTSTART:20260101', 'Line 1: Invalid DTSTART value "20260101": a DATE value requires VALUE=DATE', 1];
        yield 'short date' => ['DTSTART;VALUE=DATE:2026011', 'Line 1: Invalid DTSTART value "2026011": expected a DATE (YYYYMMDD)', 1];
        yield 'date with leading text' => ['DTSTART;VALUE=DATE:x20260101', 'Line 1: Invalid DTSTART value "x20260101": expected a DATE (YYYYMMDD)', 1];
        yield 'date with trailing text' => ['DTSTART;VALUE=DATE:20260101x', 'Line 1: Invalid DTSTART value "20260101x": expected a DATE (YYYYMMDD)', 1];
        yield 'date with trailing line feed inside' => ["DTSTART;VALUE=DATE:20260101\n x", 'Line 1: Invalid DTSTART value "20260101x": expected a DATE (YYYYMMDD)', 1];
        yield 'empty value' => ['DTSTART:', 'Line 1: Invalid DTSTART value "": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', 1];
        yield 'two DTSTART values' => ['DTSTART:20260101T090000,20260102T090000', 'Line 1: Invalid DTSTART value "20260101T090000,20260102T090000": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', 1];
        yield 'date-time with leading text' => ['DTSTART:x20260101T090000', 'Line 1: Invalid DTSTART value "x20260101T090000": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', 1];
        yield 'date-time with trailing text' => ['DTSTART:20260101T090000ZZ', 'Line 1: Invalid DTSTART value "20260101T090000ZZ": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', 1];
        yield 'February 30' => ['DTSTART:20260230T090000', 'Line 1: Invalid DTSTART value "20260230T090000": Invalid date-time 2026-02-30T09:00:00 (years 1 to 9999 are supported)', 1, InvalidValueException::class];
        yield 'February 30 date' => ['DTSTART;VALUE=DATE:20260230', 'Line 1: Invalid DTSTART value "20260230": Invalid date 2026-02-30 (years 1 to 9999 are supported)', 1, InvalidValueException::class];
        yield 'hour 24' => ['DTSTART:20260101T240000', 'Line 1: Invalid DTSTART value "20260101T240000": Invalid date-time 2026-01-01T24:00:00 (years 1 to 9999 are supported)', 1, InvalidValueException::class];
        yield 'minute 60' => ['DTSTART:20260101T096000Z', 'Line 1: Invalid DTSTART value "20260101T096000Z": Invalid date-time 2026-01-01T09:60:00 (years 1 to 9999 are supported)', 1, InvalidValueException::class];
        yield 'abbreviation as TZID' => ['DTSTART;TZID=CEST:20260101T090000', 'Line 1: Invalid TZID: Time zone "CEST" is not supported: use a named IANA zone (for example Europe/Prague) or UTC, because RFC 5545 references a time zone by its identifier', 1, InvalidValueException::class];
        yield 'offset as TZID' => ['DTSTART;TZID="+02:00":20260101T090000', 'Line 1: Invalid TZID: Time zone "+02:00" is not supported: use a named IANA zone (for example Europe/Prague) or UTC, because RFC 5545 references a time zone by its identifier', 1, InvalidValueException::class];
        yield 'TZID with UTC value' => ['DTSTART;TZID=Europe/Prague:20260101T090000Z', 'Line 1: TZID must not be used with a UTC value (ending with Z) in DTSTART', 1];

        yield 'date-time RDATE with date start' => [$dateStart . 'RDATE:20260102T090000', 'Line 2: RDATE value "20260102T090000" is a DATE-TIME, but DTSTART is a DATE', 2];
        yield 'date-time EXDATE with date start' => [$dateStart . 'EXDATE;VALUE=DATE:20260102' . "\n" . 'EXDATE:20260103T090000Z', 'Line 3: EXDATE value "20260103T090000Z" is a DATE-TIME, but DTSTART is a DATE', 3];
        yield 'period RDATE with date start' => [$dateStart . 'RDATE;VALUE=PERIOD:20260102T090000Z/PT1H', 'Line 2: RDATE value "20260102T090000Z/PT1H" is a DATE-TIME, but DTSTART is a DATE', 2];
        yield 'date RDATE with date-time start' => [$start . 'RDATE;VALUE=DATE:20260102,20260103', 'Line 2: RDATE value "20260102" is a DATE, but DTSTART is a DATE-TIME', 2];
        yield 'UTC RDATE with floating start' => [$start . 'RDATE:20260102T090000,20260102T090000Z', 'Line 2: RDATE value "20260102T090000Z" has a time zone, but DTSTART is floating', 2];
        yield 'zoned EXDATE with floating start' => [$start . 'EXDATE;TZID=Europe/Prague:20260102T090000', 'Line 2: EXDATE value "20260102T090000" has a time zone, but DTSTART is floating', 2];
        yield 'floating RDATE with UTC start' => ["DTSTART:20260101T090000Z\nRDATE:20260102T090000", 'Line 2: RDATE value "20260102T090000" is floating, but DTSTART has a time zone', 2];
        yield 'floating EXDATE with zoned start' => [$zonedStart . 'EXDATE:20260102T090000Z,20260102T090000', 'Line 2: EXDATE value "20260102T090000" is floating, but DTSTART has a time zone', 2];
        yield 'abbreviation as RDATE TZID' => [$zonedStart . 'RDATE;TZID=CEST:20260102T090000', 'Line 2: Invalid TZID: Time zone "CEST" is not supported: use a named IANA zone (for example Europe/Prague) or UTC, because RFC 5545 references a time zone by its identifier', 2, InvalidValueException::class];
        yield 'abbreviation as EXDATE TZID' => [$zonedStart . 'EXDATE;TZID=CEST:20260102T090000', 'Line 2: Invalid TZID: Time zone "CEST" is not supported: use a named IANA zone (for example Europe/Prague) or UTC, because RFC 5545 references a time zone by its identifier', 2, InvalidValueException::class];
        yield 'empty RDATE item' => [$start . 'RDATE:20260102T090000,,20260103T090000', 'Line 2: Invalid RDATE value "": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', 2];
        yield 'empty EXDATE date item' => [$dateStart . 'EXDATE;VALUE=DATE:20260102,', 'Line 2: Invalid EXDATE value "": expected a DATE (YYYYMMDD)', 2];
        yield 'period without end' => [$start . 'RDATE;VALUE=PERIOD:20260102T090000', 'Line 2: Invalid RDATE value "20260102T090000": expected a PERIOD (start/end or start/duration)', 2];
        yield 'period with two slashes' => [$start . 'RDATE;VALUE=PERIOD:20260102T090000/PT1H/PT1H', 'Line 2: Invalid RDATE value "20260102T090000/PT1H/PT1H": expected a PERIOD (start/end or start/duration)', 2];
        yield 'period with leading text' => [$start . 'RDATE;VALUE=PERIOD:x/20260102T090000/PT1H', 'Line 2: Invalid RDATE value "x/20260102T090000/PT1H": expected a PERIOD (start/end or start/duration)', 2];
        yield 'period with invalid start' => [$start . 'RDATE;VALUE=PERIOD:2026/PT1H', 'Line 2: Invalid RDATE value "2026": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', 2];
        yield 'period with date start' => [$start . 'RDATE;VALUE=PERIOD:20260102/P1D', 'Line 2: Invalid RDATE value "20260102": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', 2];
        yield 'period with invalid end date' => [$start . 'RDATE;VALUE=PERIOD:20260102T090000/20260230T090000', 'Line 2: Invalid PERIOD end value "20260230T090000": Invalid date-time 2026-02-30T09:00:00 (years 1 to 9999 are supported)', 2, InvalidValueException::class];

        yield 'empty RRULE' => [$start . 'RRULE:', 'Line 2: The rule is empty', 2];
        yield 'invalid rule value' => [$start . 'RRULE:FREQ=DAILY;COUNT=x', 'Line 2: Invalid COUNT value "x": expected an integer', 2];
        yield 'rule part error' => [$start . 'RRULE:FREQ=DAILY;X-A=1', 'Line 2: Unknown rule part "X-A=1"', 2];
        yield 'MUST NOT combination' => [$start . "RRULE:FREQ=DAILY\nRRULE:FREQ=WEEKLY;BYWEEKNO=1", 'Line 3: Invalid rule "FREQ=WEEKLY;BYWEEKNO=1": BYWEEKNO is allowed only with FREQ=YEARLY', 3, InvalidRecurrenceRuleException::class];
        yield 'folded line keeps its number' => ["DTSTART:20260101T090000\r\nRRULE:FREQ=DAILY;\r\n COUNT=x\r\nRDATE:20260102T090000", 'Line 2: Invalid COUNT value "x": expected an integer', 2];
        yield 'empty lines are counted' => ["\nDTSTART:20260101T090000\n\nRRULE:FREQ=BAD", 'Line 4: Invalid FREQ value "BAD" (allowed: SECONDLY, MINUTELY, HOURLY, DAILY, WEEKLY, MONTHLY, YEARLY)', 4];
        yield 'DTSTART inside the rule' => ['RRULE:DTSTART=20260101T090000;FREQ=DAILY', 'Line 1: Unknown rule part "DTSTART=20260101T090000"', 1];

        yield 'date UNTIL with zoned start' => [
            "DTSTART;TZID=America/New_York:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216;BYDAY=TH",
            'Line 2: RRULE FREQ=WEEKLY;UNTIL=20211216;BYDAY=TH: UNTIL must be a UTC DATE-TIME for this start (RFC 5545 section 3.3.10)',
            2,
            InvalidRecurrenceException::class,
        ];
        yield 'floating UNTIL with zoned start' => [$zonedStart . 'RRULE:FREQ=DAILY;UNTIL=20260201T090000', 'Line 2: RRULE FREQ=DAILY;UNTIL=20260201T090000: UNTIL must be a UTC DATE-TIME for this start (RFC 5545 section 3.3.10)', 2, InvalidRecurrenceException::class];
        yield 'UTC UNTIL with floating start' => [$start . 'RRULE:FREQ=DAILY;UNTIL=20260201T090000Z', 'Line 2: RRULE FREQ=DAILY;UNTIL=20260201T090000Z: UNTIL must be a floating DATE-TIME for this start (RFC 5545 section 3.3.10)', 2, InvalidRecurrenceException::class];
        yield 'date-time UNTIL with date start' => [$dateStart . 'RRULE:FREQ=DAILY;UNTIL=20260201T090000', 'Line 2: RRULE FREQ=DAILY;UNTIL=20260201T090000: UNTIL must be a DATE for this start (RFC 5545 section 3.3.10)', 2, InvalidRecurrenceException::class];
        yield 'BYHOUR with date start' => [$dateStart . 'RRULE:FREQ=DAILY;BYHOUR=9', 'Line 2: RRULE FREQ=DAILY;BYHOUR=9: BYSECOND, BYMINUTE and BYHOUR must not be used with a DATE start (RFC 5545 section 3.3.10)', 2, InvalidRecurrenceException::class];
        yield 'HOURLY with date start' => [$dateStart . 'RRULE:FREQ=HOURLY', 'Line 2: RRULE FREQ=HOURLY: a DATE start cannot have a frequency shorter than a day', 2, InvalidRecurrenceException::class];
    }

    public function testUnknownTimeZone(): void
    {
        foreach (['DTSTART;TZID=Mars/Olympus:20260101T090000', "DTSTART:20260101T090000Z\nRDATE;TZID=Mars/Olympus:20260101T090000"] as $index => $input) {
            try {
                (new RecurrenceParser())->parseRecurrence($input);
                self::fail('Expected ParseException');
            } catch (ParseException $e) {
                self::assertSame('Line ' . ($index + 1) . ': Unknown time zone "Mars/Olympus" in TZID', $e->getMessage());
                self::assertSame($index + 1, $e->lineNumber);
                self::assertInstanceOf(Throwable::class, $e->getPrevious());
            }
        }
    }

    public function testZonedValuesAreConvertedToTheStartZone(): void
    {
        $recurrence = (new RecurrenceParser())->parseRecurrence("DTSTART;TZID=Europe/Prague:20260101T090000\nRDATE;TZID=Asia/Tokyo:20260102T090000,20260103T090000")->value;

        self::assertSame(
            ['2026-01-02T01:00:00+01:00 Europe/Prague', '2026-01-03T01:00:00+01:00 Europe/Prague'],
            array_map(self::describe(...), $recurrence->getAddedDates()),
        );
    }

    public function testValueTypesOfStart(): void
    {
        $parser = new RecurrenceParser();

        self::assertInstanceOf(CalendarDate::class, $parser->parseRecurrence('DTSTART;VALUE=DATE:20260101')->value->getStart());
        self::assertInstanceOf(FloatingDateTime::class, $parser->parseRecurrence('DTSTART:20260101T090000')->value->getStart());
        self::assertInstanceOf(DateTimeImmutable::class, $parser->parseRecurrence('DTSTART:20260101T090000Z')->value->getStart());
    }

}
