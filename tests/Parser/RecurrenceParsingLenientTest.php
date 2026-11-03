<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Parser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\InvalidValueException;
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
use ShipMonk\RRule\RecurrenceRule;
use Throwable;
use function array_map;

#[CoversClass(RecurrenceParser::class)]
#[CoversClass(RecurrenceTextParser::class)]
#[CoversClass(RuleParser::class)]
#[CoversClass(RawRule::class)]
#[CoversClass(ValueParser::class)]
#[CoversClass(ParseContext::class)]
#[CoversClass(ParseErrors::class)]
#[CoversClass(ContentLine::class)]
#[CoversClass(ContentLineReader::class)]
final class RecurrenceParsingLenientTest extends ParserTestCase
{

    /**
     * @param list<array{ParseWarningCode, string, int|null}> $warnings
     */
    #[DataProvider('provideDeviations')]
    public function testDeviation(
        string $input,
        string $expected,
        array $warnings,
    ): void
    {
        $result = (new RecurrenceParser(ParseMode::Lenient))->parseRecurrence($input);

        self::assertSame($expected, self::serialize($result->value));
        self::assertWarnings($warnings, $result->warnings);
    }

    /**
     * @return iterable<string, array{string, string, list<array{ParseWarningCode, string, int|null}>}>
     */
    public static function provideDeviations(): iterable
    {
        yield 'unknown properties' => [
            "BEGIN:VEVENT\nDTSTART:20260101T090000\nSUMMARY:Meeting\nX-FOO;X-BAR=1:x\nRRULE:FREQ=DAILY\nEND:VEVENT",
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY",
            [
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property BEGIN', 1],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property SUMMARY', 3],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property X-FOO', 4],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property END', 6],
            ],
        ];

        yield 'EXRULE' => [
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY\nEXRULE:FREQ=WEEKLY;BYDAY=SA,SU\nexrule:FREQ=MONTHLY;BYMONTHDAY=1",
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY\nEXRULE:FREQ=WEEKLY;BYDAY=SA,SU\nEXRULE:FREQ=MONTHLY;BYMONTHDAY=1",
            [
                [ParseWarningCode::ExclusionRuleAccepted, 'Accepted EXRULE, which RFC 5545 removed', 3],
                [ParseWarningCode::ExclusionRuleAccepted, 'Accepted EXRULE, which RFC 5545 removed', 4],
            ],
        ];

        yield 'TZID with a UTC DTSTART' => [
            'DTSTART;TZID=Mars/Olympus:20260101T090000Z',
            'DTSTART:20260101T090000Z',
            [[ParseWarningCode::TimeZoneIgnoredForUtcValue, 'Ignored TZID=Mars/Olympus, because the DTSTART value is in UTC', 1]],
        ];

        yield 'TZID with UTC RDATE values' => [
            "DTSTART:20260101T090000Z\nRDATE;TZID=Europe/Prague:20260102T090000Z,20260103T090000Z",
            "DTSTART:20260101T090000Z\nRDATE:20260102T090000Z,20260103T090000Z",
            [[ParseWarningCode::TimeZoneIgnoredForUtcValue, 'Ignored TZID=Europe/Prague, because the RDATE value is in UTC', 2]],
        ];

        yield 'TZID with mixed EXDATE values' => [
            "DTSTART;TZID=Europe/Prague:20260101T090000\nEXDATE;TZID=Europe/Prague:20260102T090000,20260103T090000Z",
            "DTSTART;TZID=Europe/Prague:20260101T090000\nEXDATE;TZID=Europe/Prague:20260102T090000,20260103T100000",
            [[ParseWarningCode::TimeZoneIgnoredForUtcValue, 'Ignored TZID=Europe/Prague, because the EXDATE value is in UTC', 2]],
        ];

        yield 'date RDATE with floating start' => [
            "DTSTART:20260101T090000\nRDATE;VALUE=DATE:20260105,20260106",
            "DTSTART:20260101T090000\nRDATE:20260105T000000,20260106T000000",
            [[ParseWarningCode::DateAdditionForDateTimeStart, 'Converted the RDATE;VALUE=DATE values to the start of each day, because DTSTART is a DATE-TIME', 2]],
        ];

        yield 'date RDATE with zoned start' => [
            "DTSTART;TZID=Europe/Prague:20260101T090000\nRDATE;VALUE=DATE:20260105",
            "DTSTART;TZID=Europe/Prague:20260101T090000\nRDATE;TZID=Europe/Prague:20260105T000000",
            [[ParseWarningCode::DateAdditionForDateTimeStart, 'Converted the RDATE;VALUE=DATE values to the start of each day, because DTSTART is a DATE-TIME', 2]],
        ];

        yield 'date RDATE with UTC start' => [
            "DTSTART:20260101T090000Z\nRDATE;VALUE=DATE:20260105",
            "DTSTART:20260101T090000Z\nRDATE:20260105T000000Z",
            [[ParseWarningCode::DateAdditionForDateTimeStart, 'Converted the RDATE;VALUE=DATE values to the start of each day, because DTSTART is a DATE-TIME', 2]],
        ];

        yield 'date EXDATE with zoned start' => [
            "DTSTART;TZID=Europe/Prague:20260101T090000\nRRULE:FREQ=DAILY\nEXDATE;VALUE=DATE:20260105,20260106",
            "DTSTART;TZID=Europe/Prague:20260101T090000\nRRULE:FREQ=DAILY\nEXDATE;VALUE=DATE:20260105,20260106",
            [[ParseWarningCode::DateExclusionForDateTimeStart, 'EXDATE;VALUE=DATE with a DATE-TIME start excludes every occurrence on these dates', 3]],
        ];

        yield 'period RDATE' => [
            "DTSTART:20260101T090000\nRDATE;VALUE=PERIOD:20260105T090000/PT1H",
            "DTSTART:20260101T090000\nRDATE:20260105T090000",
            [[ParseWarningCode::PeriodEndIgnored, 'Ignored the end of each RDATE period: only the period start is used', 2]],
        ];

        yield 'date UNTIL with zoned start (Google)' => [
            "DTSTART;TZID=America/New_York:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216;BYDAY=TH",
            "DTSTART;TZID=America/New_York:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211217T045959Z;BYDAY=TH",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216 to UNTIL=20211217T045959Z, because DTSTART has a different value type', 2]],
        ];

        yield 'date UNTIL with UTC start' => [
            "DTSTART:20210902T090000Z\nRRULE:FREQ=WEEKLY;UNTIL=20211216",
            "DTSTART:20210902T090000Z\nRRULE:FREQ=WEEKLY;UNTIL=20211216T235959Z",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216 to UNTIL=20211216T235959Z, because DTSTART has a different value type', 2]],
        ];

        yield 'date UNTIL with floating start' => [
            "DTSTART:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216",
            "DTSTART:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T235959",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216 to UNTIL=20211216T235959, because DTSTART has a different value type', 2]],
        ];

        yield 'floating UNTIL with zoned start' => [
            "DTSTART;TZID=America/New_York:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000",
            "DTSTART;TZID=America/New_York:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T140000Z",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216T090000 to UNTIL=20211216T140000Z, because DTSTART has a different value type', 2]],
        ];

        yield 'floating UNTIL with UTC start' => [
            "DTSTART:20210902T090000Z\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000",
            "DTSTART:20210902T090000Z\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000Z",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216T090000 to UNTIL=20211216T090000Z, because DTSTART has a different value type', 2]],
        ];

        yield 'UTC UNTIL with floating start' => [
            "DTSTART:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000Z",
            "DTSTART:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216T090000Z to UNTIL=20211216T090000, because DTSTART has a different value type', 2]],
        ];

        yield 'floating UNTIL with date start' => [
            "DTSTART;VALUE=DATE:20210902\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000",
            "DTSTART;VALUE=DATE:20210902\nRRULE:FREQ=WEEKLY;UNTIL=20211216",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216T090000 to UNTIL=20211216, because DTSTART has a different value type', 2]],
        ];

        yield 'UTC UNTIL with date start' => [
            "DTSTART;VALUE=DATE:20210902\nRRULE:FREQ=WEEKLY;UNTIL=20211216T235959Z",
            "DTSTART;VALUE=DATE:20210902\nRRULE:FREQ=WEEKLY;UNTIL=20211216",
            [[ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216T235959Z to UNTIL=20211216, because DTSTART has a different value type', 2]],
        ];

        yield 'matching UNTIL types' => [
            "DTSTART;TZID=Europe/Prague:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000Z\nEXRULE:FREQ=DAILY;UNTIL=20211216T100000Z",
            "DTSTART;TZID=Europe/Prague:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000Z\nEXRULE:FREQ=DAILY;UNTIL=20211216T100000Z",
            [[ParseWarningCode::ExclusionRuleAccepted, 'Accepted EXRULE, which RFC 5545 removed', 3]],
        ];

        yield 'matching date UNTIL' => [
            "DTSTART;VALUE=DATE:20210902\nRRULE:FREQ=WEEKLY;UNTIL=20211216",
            "DTSTART;VALUE=DATE:20210902\nRRULE:FREQ=WEEKLY;UNTIL=20211216",
            [],
        ];

        yield 'matching floating UNTIL' => [
            "DTSTART:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000",
            "DTSTART:20210902T090000\nRRULE:FREQ=WEEKLY;UNTIL=20211216T090000",
            [],
        ];

        yield 'UNTIL of an exclusion rule' => [
            "DTSTART:20210902T090000Z\nEXRULE:FREQ=WEEKLY;UNTIL=20211216",
            "DTSTART:20210902T090000Z\nEXRULE:FREQ=WEEKLY;UNTIL=20211216T235959Z",
            [
                [ParseWarningCode::ExclusionRuleAccepted, 'Accepted EXRULE, which RFC 5545 removed', 2],
                [ParseWarningCode::UntilValueTypeConverted, 'Converted UNTIL=20211216 to UNTIL=20211216T235959Z, because DTSTART has a different value type', 2],
            ],
        ];

        yield 'time parts with date start' => [
            "DTSTART;VALUE=DATE:20260101\nRRULE:FREQ=DAILY;BYHOUR=9,10;BYMINUTE=30;BYSECOND=15",
            "DTSTART;VALUE=DATE:20260101\nRRULE:FREQ=DAILY",
            [
                [ParseWarningCode::TimePartsIgnoredForDateStart, 'Ignored BYSECOND=15: RFC 5545 section 3.3.10 says that it must be ignored with a DATE start', 2],
                [ParseWarningCode::TimePartsIgnoredForDateStart, 'Ignored BYMINUTE=30: RFC 5545 section 3.3.10 says that it must be ignored with a DATE start', 2],
                [ParseWarningCode::TimePartsIgnoredForDateStart, 'Ignored BYHOUR=9,10: RFC 5545 section 3.3.10 says that it must be ignored with a DATE start', 2],
            ],
        ];

        yield 'time parts with date start leave BYSETPOS alone' => [
            "DTSTART;VALUE=DATE:20260101\nRRULE:FREQ=DAILY;BYHOUR=9;BYSETPOS=1",
            "DTSTART;VALUE=DATE:20260101\nRRULE:FREQ=DAILY",
            [
                [ParseWarningCode::TimePartsIgnoredForDateStart, 'Ignored BYHOUR=9: RFC 5545 section 3.3.10 says that it must be ignored with a DATE start', 2],
                [ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYSETPOS=1: BYSETPOS must be used together with another BYxxx rule part', 2],
            ],
        ];

        yield 'time parts with date-time start are kept' => [
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY;BYHOUR=9;BYMINUTE=30;BYSECOND=15",
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY;BYSECOND=15;BYMINUTE=30;BYHOUR=9",
            [],
        ];

        yield 'rule warnings have the line number' => [
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY\nRRULE:FREQ=WEEKLY;BYMONTHDAY=1;X-A=1",
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY\nRRULE:FREQ=WEEKLY",
            [
                [ParseWarningCode::UnknownRulePartIgnored, 'Ignored the unknown rule part "X-A=1"', 3],
                [ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYMONTHDAY=1: BYMONTHDAY is not allowed with FREQ=WEEKLY', 3],
            ],
        ];

        yield 'warnings are sorted by line' => [
            "DTSTART;TZID=Europe/Prague:20260101T090000\nEXDATE;VALUE=DATE:20260105\nSUMMARY:x\nEXDATE;VALUE=DATE:20260106\nLOCATION:y",
            "DTSTART;TZID=Europe/Prague:20260101T090000\nEXDATE;VALUE=DATE:20260105,20260106",
            [
                [ParseWarningCode::DateExclusionForDateTimeStart, 'EXDATE;VALUE=DATE with a DATE-TIME start excludes every occurrence on these dates', 2],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property SUMMARY', 3],
                [ParseWarningCode::DateExclusionForDateTimeStart, 'EXDATE;VALUE=DATE with a DATE-TIME start excludes every occurrence on these dates', 4],
                [ParseWarningCode::UnknownPropertyIgnored, 'Ignored the property LOCATION', 5],
            ],
        ];

        yield 'DTSTART inside the rule (rrule.js)' => [
            'RRULE:DTSTART=20120201T093000Z;FREQ=WEEKLY;INTERVAL=5;UNTIL=20130130T230000Z;BYDAY=MO,FR',
            "DTSTART:20120201T093000Z\nRRULE:FREQ=WEEKLY;INTERVAL=5;UNTIL=20130130T230000Z;BYDAY=MO,FR",
            [[ParseWarningCode::StartInsideRule, 'Used DTSTART=20120201T093000Z from inside RRULE as the start', 1]],
        ];

        yield 'date DTSTART inside the rule' => [
            "RDATE;VALUE=DATE:20260110\nRRULE:FREQ=DAILY;DTSTART=20260101;UNTIL=20260105",
            "DTSTART;VALUE=DATE:20260101\nRRULE:FREQ=DAILY;UNTIL=20260105\nRDATE;VALUE=DATE:20260110",
            [[ParseWarningCode::StartInsideRule, 'Used DTSTART=20260101 from inside RRULE as the start', 2]],
        ];

        yield 'DTSTART inside several rules' => [
            "RRULE:FREQ=DAILY;DTSTART=20260101T090000\nRRULE:FREQ=WEEKLY;DTSTART=20260102T090000\nEXRULE:FREQ=MONTHLY;DTSTART=20260103T090000",
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY\nRRULE:FREQ=WEEKLY\nEXRULE:FREQ=MONTHLY",
            [
                [ParseWarningCode::StartInsideRule, 'Used DTSTART=20260101T090000 from inside RRULE as the start', 1],
                [ParseWarningCode::StartInsideRule, 'Ignored DTSTART=20260102T090000 inside RRULE, because the start is set on line 1', 2],
                [ParseWarningCode::ExclusionRuleAccepted, 'Accepted EXRULE, which RFC 5545 removed', 3],
                [ParseWarningCode::StartInsideRule, 'Ignored DTSTART=20260103T090000 inside EXRULE, because the start is set on line 1', 3],
            ],
        ];

        yield 'DTSTART inside the rule and a DTSTART property' => [
            "RRULE:FREQ=DAILY;DTSTART=garbage\nDTSTART:20260101T090000",
            "DTSTART:20260101T090000\nRRULE:FREQ=DAILY",
            [[ParseWarningCode::StartInsideRule, 'Ignored DTSTART=garbage inside RRULE, because the start is set on line 2', 1]],
        ];
    }

    public function testExclusionRuleValue(): void
    {
        $recurrence = (new RecurrenceParser(ParseMode::Lenient))->parseRecurrence("DTSTART:20260101T090000\nEXRULE:FREQ=WEEKLY;BYDAY=SA")->value;

        self::assertSame([], $recurrence->getRules());
        self::assertSame(['FREQ=WEEKLY;BYDAY=SA'], array_map(static fn (RecurrenceRule $rule): string => $rule->toString(), $recurrence->getExclusionRules()));
    }

    public function testConvertedRdateValues(): void
    {
        $parser = new RecurrenceParser(ParseMode::Lenient);

        self::assertSame(
            ['2026-03-29T00:00:00 floating'],
            array_map(self::describe(...), $parser->parseRecurrence("DTSTART:20260101T090000\nRDATE;VALUE=DATE:20260329")->value->getAddedDates()),
        );
        self::assertSame(
            ['2026-03-29T00:00:00+01:00 Europe/Prague'],
            array_map(self::describe(...), $parser->parseRecurrence("DTSTART;TZID=Europe/Prague:20260101T090000\nRDATE;VALUE=DATE:20260329")->value->getAddedDates()),
        );
        self::assertSame(
            ['2026-01-05 date', '2026-01-06 date'],
            array_map(self::describe(...), $parser->parseRecurrence("DTSTART:20260101T090000\nEXDATE;VALUE=DATE:20260105,20260106")->value->getExcludedDays()),
        );
    }

    /**
     * @param class-string<Throwable>|null $previous
     */
    #[DataProvider('provideErrors')]
    public function testError(
        string $input,
        string $message,
        ?int $lineNumber,
        ?string $previous = null,
    ): void
    {
        self::assertRecurrenceError($input, $message, $lineNumber, ParseMode::Lenient, $previous);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int|null, 3?: class-string}>
     */
    public static function provideErrors(): iterable
    {
        yield 'UNTIL after year 9999 in UTC' => [
            "DTSTART;TZID=America/Los_Angeles:99991201T090000\nRRULE:FREQ=DAILY;UNTIL=99991231",
            'Line 2: Invalid UNTIL value "99991231": Invalid date-time 10000-01-01T07:59:59 (years 1 to 9999 are supported)',
            2,
            InvalidValueException::class,
        ];
        yield 'invalid DTSTART inside the rule' => [
            'RRULE:FREQ=DAILY;DTSTART=2026',
            'Line 1: Invalid DTSTART value "2026": expected YYYYMMDD, YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ',
            1,
        ];
        yield 'no start at all' => ["RRULE:FREQ=DAILY\nSUMMARY:x", 'DTSTART is required', null];
        yield 'duplicate DTSTART' => ["DTSTART:20260101T090000\nDTSTART:20260101T090000", 'Line 2: DTSTART occurs more than once (first on line 1)', 2];
        yield 'TZID with DATE' => ['DTSTART;VALUE=DATE;TZID=Europe/Prague:20260101', 'Line 1: TZID must not be used with VALUE=DATE (RFC 5545 section 3.2.19) in DTSTART', 1];
        yield 'date-time RDATE with date start' => ["DTSTART;VALUE=DATE:20260101\nRDATE:20260102T090000", 'Line 2: RDATE value "20260102T090000" is a DATE-TIME, but DTSTART is a DATE', 2];
        yield 'floating RDATE with zoned start' => ["DTSTART;TZID=Europe/Prague:20260101T090000\nRDATE:20260102T090000", 'Line 2: RDATE value "20260102T090000" is floating, but DTSTART has a time zone', 2];
        yield 'HOURLY exclusion rule with date start' => [
            "DTSTART;VALUE=DATE:20260101\nEXRULE:FREQ=HOURLY",
            'Line 2: EXRULE FREQ=HOURLY: a DATE start cannot have a frequency shorter than a day',
            2,
            InvalidRecurrenceException::class,
        ];
    }

}
