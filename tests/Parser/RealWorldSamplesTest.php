<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Parser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Internal\Parser\ContentLine;
use ShipMonk\RRule\Internal\Parser\ContentLineReader;
use ShipMonk\RRule\Internal\Parser\ParseContext;
use ShipMonk\RRule\Internal\Parser\ParseErrors;
use ShipMonk\RRule\Internal\Parser\RawRule;
use ShipMonk\RRule\Internal\Parser\RecurrenceTextParser;
use ShipMonk\RRule\Internal\Parser\RuleParser;
use ShipMonk\RRule\Internal\Parser\ValueParser;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\ParseWarningCode;
use ShipMonk\RRule\Parser\RecurrenceParser;

/**
 * Text written by real producers. Each sample states whether strict mode accepts it.
 */
#[CoversClass(RecurrenceParser::class)]
#[CoversClass(RecurrenceTextParser::class)]
#[CoversClass(RuleParser::class)]
#[CoversClass(RawRule::class)]
#[CoversClass(ValueParser::class)]
#[CoversClass(ParseContext::class)]
#[CoversClass(ParseErrors::class)]
#[CoversClass(ContentLine::class)]
#[CoversClass(ContentLineReader::class)]
final class RealWorldSamplesTest extends ParserTestCase
{

    /**
     * @param list<array{ParseWarningCode, string, int|null}> $warnings
     */
    #[DataProvider('provideStandardSamples')]
    public function testStandardSample(
        string $input,
        string $expected,
        array $warnings = [],
    ): void
    {
        foreach ([ParseMode::Strict, ParseMode::Lenient] as $mode) {
            $result = (new RecurrenceParser($mode))->parseRecurrence($input);

            self::assertSame($expected, self::serialize($result->value));
            self::assertWarnings($warnings, $result->warnings);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: list<array{ParseWarningCode, string, int|null}>}>
     */
    public static function provideStandardSamples(): iterable
    {
        yield 'rrule.js toString()' => [
            "DTSTART:20120201T093000Z\nRRULE:FREQ=WEEKLY;INTERVAL=5;UNTIL=20130130T230000Z;BYDAY=MO,FR",
            "DTSTART:20120201T093000Z\nRRULE:FREQ=WEEKLY;INTERVAL=5;UNTIL=20130130T230000Z;BYDAY=MO,FR",
        ];

        yield 'rrule.js with TZID' => [
            "DTSTART;TZID=America/Denver:20181101T190000\nRRULE:FREQ=WEEKLY;BYDAY=MO,WE,TH;INTERVAL=1;COUNT=3",
            "DTSTART;TZID=America/Denver:20181101T190000\nRRULE:FREQ=WEEKLY;COUNT=3;BYDAY=MO,WE,TH",
        ];

        yield 'python-dateutil str(rrule)' => [
            "DTSTART:19970902T090000\nRRULE:FREQ=MONTHLY;COUNT=10;BYDAY=+1FR",
            "DTSTART:19970902T090000\nRRULE:FREQ=MONTHLY;COUNT=10;BYDAY=1FR",
        ];

        yield 'python-dateutil rruleset' => [
            "DTSTART:19970902T090000\nRRULE:FREQ=YEARLY;COUNT=2;BYDAY=TU\nRRULE:FREQ=YEARLY;COUNT=1;BYDAY=TH\nRDATE:19970904T090000\nEXDATE:19970916T090000",
            "DTSTART:19970902T090000\nRRULE:FREQ=YEARLY;COUNT=2;BYDAY=TU\nRRULE:FREQ=YEARLY;COUNT=1;BYDAY=TH\nRDATE:19970904T090000\nEXDATE:19970916T090000",
        ];

        yield 'Google Calendar with UTC UNTIL' => [
            "DTSTART;TZID=Europe/Prague:20260105T090000\r\nRRULE:FREQ=WEEKLY;WKST=MO;UNTIL=20260330T065959Z;INTERVAL=2;BYDAY=MO\r\nEXDATE;TZID=Europe/Prague:20260202T090000\r\n",
            "DTSTART;TZID=Europe/Prague:20260105T090000\nRRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20260330T065959Z;BYDAY=MO\nEXDATE;TZID=Europe/Prague:20260202T090000",
        ];

        yield 'Apple Calendar' => [
            "DTSTART;TZID=America/Los_Angeles:20260315T100000\r\nRRULE:FREQ=MONTHLY;INTERVAL=1;BYDAY=3SU\r\n",
            "DTSTART;TZID=America/Los_Angeles:20260315T100000\nRRULE:FREQ=MONTHLY;BYDAY=3SU",
        ];

        yield 'Outlook all-day event' => [
            "DTSTART;VALUE=DATE:20260105\r\nRRULE:FREQ=WEEKLY;UNTIL=20260330;INTERVAL=1;BYDAY=MO;WKST=SU\r\nEXDATE;VALUE=DATE:20260119\r\n",
            "DTSTART;VALUE=DATE:20260105\nRRULE:FREQ=WEEKLY;UNTIL=20260330;BYDAY=MO;WKST=SU\nEXDATE;VALUE=DATE:20260119",
        ];

        yield 'Thunderbird with folded RDATE' => [
            "DTSTART;TZID=Europe/Berlin:20260105T080000\r\n"
            . "RDATE;TZID=Europe/Berlin:20260106T080000,20260107T080000,20260108T080000,2\r\n"
            . " 0260109T080000\r\n",
            "DTSTART;TZID=Europe/Berlin:20260105T080000\nRDATE;TZID=Europe/Berlin:20260106T080000,20260107T080000,20260108T080000,20\n 260109T080000",
        ];

        yield 'Outlook date exclusion with a date-time start' => [
            "DTSTART;TZID=Europe/Berlin:20240105T100000\r\nRRULE:FREQ=WEEKLY;BYDAY=FR\r\nEXDATE;VALUE=DATE:20240112\r\n",
            "DTSTART;TZID=Europe/Berlin:20240105T100000\nRRULE:FREQ=WEEKLY;BYDAY=FR\nEXDATE;VALUE=DATE:20240112",
            [[ParseWarningCode::DateExclusionForDateTimeStart, 'EXDATE;VALUE=DATE with a DATE-TIME start excludes every occurrence on these dates', 3]],
        ];

        yield 'RFC 5545 RDATE example' => [
            "DTSTART:19960403T020000Z\nRDATE;VALUE=PERIOD:19960403T020000Z/19960403T040000Z,\n 19960404T010000Z/PT3H",
            "DTSTART:19960403T020000Z\nRDATE:19960403T020000Z,19960404T010000Z",
            [[ParseWarningCode::PeriodEndIgnored, 'Ignored the end of each RDATE period: only the period start is used', 2]],
        ];

        yield 'RFC 7529 leap day birthday' => [
            "DTSTART;VALUE=DATE:20160229\nRRULE:RSCALE=GREGORIAN;FREQ=YEARLY;SKIP=FORWARD",
            "DTSTART;VALUE=DATE:20160229\nRRULE:FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=FORWARD",
        ];
    }

    /**
     * @param list<array{ParseWarningCode, string, int|null}> $warnings
     */
    #[DataProvider('provideLenientSamples')]
    public function testLenientSample(
        string $input,
        string $strictError,
        string $expected,
        array $warnings,
    ): void
    {
        try {
            (new RecurrenceParser())->parseRecurrence($input);
            self::fail('Strict mode must reject the sample');
        } catch (ParseException $e) {
            self::assertSame($strictError, $e->getMessage());
        }

        $result = (new RecurrenceParser(ParseMode::Lenient))->parseRecurrence($input);

        self::assertSame($expected, self::serialize($result->value));
        self::assertWarnings($warnings, $result->warnings);
    }

    /**
     * @return iterable<string, array{string, string, string, list<array{ParseWarningCode, string, int|null}>}>
     */
    public static function provideLenientSamples(): iterable
    {
        yield 'Google Calendar with DATE UNTIL' => [
            "DTSTART;TZID=America/New_York:20210902T090000\r\nRRULE:FREQ=WEEKLY;UNTIL=20211216;BYDAY=TH\r\n",
            'Line 2: RRULE FREQ=WEEKLY;UNTIL=20211216;BYDAY=TH: UNTIL must be a UTC DATE-TIME for this start (RFC 5545 section 3.3.10)',
            "DTSTART;TZID=America/New_York:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211217T045959Z;BYDAY=TH",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216 to UNTIL=20211217T045959Z, because DTSTART has a different value type', 2]],
        ];

        yield 'Office 365 negative COUNT' => [
            "DTSTART;TZID=Europe/Berlin:20240105T100000\r\nRRULE:FREQ=WEEKLY;COUNT=-1;BYDAY=FR\r\nEXDATE;VALUE=DATE:20240112\r\n",
            'Line 2: Invalid COUNT value "-1": COUNT must be a positive integer, -1 given',
            "DTSTART;TZID=Europe/Berlin:20240105T100000\nRRULE:FREQ=WEEKLY;BYDAY=FR\nEXDATE;VALUE=DATE:20240112",
            [
                [ParseWarningCode::InvalidCountIgnored, 'Ignored COUNT=-1: COUNT must be a positive integer', 2],
                [ParseWarningCode::DateExclusionForDateTimeStart, 'EXDATE;VALUE=DATE with a DATE-TIME start excludes every occurrence on these dates', 3],
            ],
        ];

        yield 'old rrule.js with DTSTART inside the rule' => [
            'RRULE:DTSTART=20120201T093000Z;FREQ=WEEKLY;INTERVAL=5;UNTIL=20130130T230000Z;BYDAY=MO,FR',
            'Line 1: Unknown rule part "DTSTART=20120201T093000Z"',
            "DTSTART:20120201T093000Z\nRRULE:FREQ=WEEKLY;INTERVAL=5;UNTIL=20130130T230000Z;BYDAY=MO,FR",
            [[ParseWarningCode::StartInsideRule, 'Used DTSTART=20120201T093000Z from inside RRULE as the start', 1]],
        ];

        yield 'libical corpus: UTC UNTIL with floating start' => [
            "DTSTART:19970902T090000\nRRULE:FREQ=DAILY;UNTIL=19971224T000000Z",
            'Line 2: RRULE FREQ=DAILY;UNTIL=19971224T000000Z: UNTIL must be a floating DATE-TIME for this start (RFC 5545 section 3.3.10)',
            "DTSTART:19970902T090000\nRRULE:FREQ=DAILY;UNTIL=19971224T000000",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=19971224T000000Z to UNTIL=19971224T000000, because DTSTART has a different value type', 2]],
        ];

        yield 'full VEVENT' => [
            "BEGIN:VEVENT\r\nUID:123@example.com\r\nSUMMARY:Stand-up\r\nDTSTART;TZID=Europe/Prague:20260105T093000\r\nDTEND;TZID=Europe/Prague:20260105T094500\r\nRRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR\r\nEND:VEVENT\r\n",
            'Line 1: Unknown property BEGIN (allowed: DTSTART, RRULE, RDATE, EXDATE)',
            "DTSTART;TZID=Europe/Prague:20260105T093000\nRRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR",
            [
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property BEGIN', 1],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property UID', 2],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property SUMMARY', 3],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property DTEND', 5],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property END', 7],
            ],
        ];

        yield 'trailing semicolon' => [
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY;COUNT=3;",
            'Line 2: The rule "FREQ=DAILY;COUNT=3;" contains an empty part',
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY;COUNT=3",
            [[ParseWarningCode::EmptyRulePartIgnored, 'Ignored an empty part of the rule "FREQ=DAILY;COUNT=3;"', 2]],
        ];
    }

    public function testRecurrenceRuleThatRecurrenceRejects(): void
    {
        self::assertRecurrenceError(
            "DTSTART;VALUE=DATE:20260101\nRRULE:FREQ=MINUTELY;INTERVAL=15",
            'Line 2: RRULE FREQ=MINUTELY;INTERVAL=15: a DATE start cannot have a frequency shorter than a day',
            2,
            ParseMode::Lenient,
            InvalidRecurrenceException::class,
        );
    }

}
