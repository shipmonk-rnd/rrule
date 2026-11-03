<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Parser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipMonk\RRule\Internal\Parser\ContentLineReader;
use ShipMonk\RRule\Internal\Parser\ParseContext;
use ShipMonk\RRule\Internal\Parser\ParseErrors;
use ShipMonk\RRule\Internal\Parser\RawRule;
use ShipMonk\RRule\Internal\Parser\RuleParser;
use ShipMonk\RRule\Internal\Parser\ValueParser;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\ParseWarningCode;
use ShipMonk\RRule\Parser\RecurrenceParser;

#[CoversClass(RecurrenceParser::class)]
#[CoversClass(RuleParser::class)]
#[CoversClass(RawRule::class)]
#[CoversClass(ValueParser::class)]
#[CoversClass(ParseContext::class)]
#[CoversClass(ParseErrors::class)]
#[CoversClass(ContentLineReader::class)]
final class RuleParsingLenientTest extends ParserTestCase
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
        $result = (new RecurrenceParser(ParseMode::Lenient))->parseRule($input);

        self::assertSame($expected, $result->value->toString());
        self::assertWarnings($warnings, $result->warnings);
    }

    /**
     * @return iterable<string, array{string, string, list<array{ParseWarningCode, string, int|null}>}>
     */
    public static function provideDeviations(): iterable
    {
        yield 'unknown and X- parts' => [
            'FREQ=DAILY;FOO=1;X-NAME=a=b',
            'FREQ=DAILY',
            [
                [ParseWarningCode::UnknownRulePartIgnored, 'Ignored the unknown rule part "FOO=1"', null],
                [ParseWarningCode::UnknownRulePartIgnored, 'Ignored the unknown rule part "X-NAME=a=b"', null],
            ],
        ];

        yield 'unknown part with an empty value' => [
            'FREQ=DAILY;X-EMPTY=',
            'FREQ=DAILY',
            [[ParseWarningCode::UnknownRulePartIgnored, 'Ignored the unknown rule part "X-EMPTY="', null]],
        ];

        yield 'duplicate parts keep the first' => [
            'FREQ=DAILY;BYHOUR=1;freq=WEEKLY;BYHOUR=bad',
            'FREQ=DAILY;BYHOUR=1',
            [
                [ParseWarningCode::DuplicateRulePartIgnored, 'Ignored the duplicate rule part "freq=WEEKLY"', null],
                [ParseWarningCode::DuplicateRulePartIgnored, 'Ignored the duplicate rule part "BYHOUR=bad"', null],
            ],
        ];

        yield 'empty parts' => [
            ';FREQ=DAILY;;',
            'FREQ=DAILY',
            [
                [ParseWarningCode::EmptyRulePartIgnored, 'Ignored an empty part of the rule ";FREQ=DAILY;;"', null],
                [ParseWarningCode::EmptyRulePartIgnored, 'Ignored an empty part of the rule ";FREQ=DAILY;;"', null],
                [ParseWarningCode::EmptyRulePartIgnored, 'Ignored an empty part of the rule ";FREQ=DAILY;;"', null],
            ],
        ];

        yield 'BYWEEKNO with MONTHLY' => [
            'FREQ=MONTHLY;BYWEEKNO=20,21',
            'FREQ=MONTHLY',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYWEEKNO=20,21: BYWEEKNO is allowed only with FREQ=YEARLY', null]],
        ];

        yield 'BYYEARDAY with DAILY' => [
            'FREQ=DAILY;BYYEARDAY=100',
            'FREQ=DAILY',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYYEARDAY=100: BYYEARDAY is not allowed with FREQ=DAILY', null]],
        ];

        yield 'BYYEARDAY with WEEKLY' => [
            'FREQ=WEEKLY;BYYEARDAY=-1',
            'FREQ=WEEKLY',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYYEARDAY=-1: BYYEARDAY is not allowed with FREQ=WEEKLY', null]],
        ];

        yield 'BYYEARDAY with MONTHLY' => [
            'FREQ=MONTHLY;BYYEARDAY=1',
            'FREQ=MONTHLY',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYYEARDAY=1: BYYEARDAY is not allowed with FREQ=MONTHLY', null]],
        ];

        yield 'BYYEARDAY with HOURLY is allowed' => ['FREQ=HOURLY;BYYEARDAY=1', 'FREQ=HOURLY;BYYEARDAY=1', []];
        yield 'BYYEARDAY with YEARLY is allowed' => ['FREQ=YEARLY;BYYEARDAY=1', 'FREQ=YEARLY;BYYEARDAY=1', []];
        yield 'BYMONTHDAY with MONTHLY is allowed' => ['FREQ=MONTHLY;BYMONTHDAY=1', 'FREQ=MONTHLY;BYMONTHDAY=1', []];

        yield 'BYMONTHDAY with WEEKLY' => [
            'FREQ=WEEKLY;BYMONTHDAY=1,15',
            'FREQ=WEEKLY',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYMONTHDAY=1,15: BYMONTHDAY is not allowed with FREQ=WEEKLY', null]],
        ];

        yield 'BYDAY ordinals with WEEKLY' => [
            'FREQ=WEEKLY;BYDAY=1MO,-1MO,TU,2we',
            'FREQ=WEEKLY;BYDAY=MO,TU,WE',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored the ordinals in BYDAY=1MO,-1MO,TU,2we: ordinals are allowed only with FREQ=MONTHLY or FREQ=YEARLY', null]],
        ];

        yield 'BYDAY ordinals with DAILY' => [
            'FREQ=DAILY;BYDAY=TU,1MO',
            'FREQ=DAILY;BYDAY=TU,MO',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored the ordinals in BYDAY=TU,1MO: ordinals are allowed only with FREQ=MONTHLY or FREQ=YEARLY', null]],
        ];

        yield 'BYDAY ordinals with YEARLY and BYWEEKNO' => [
            'FREQ=YEARLY;BYWEEKNO=1;BYDAY=1MO',
            'FREQ=YEARLY;BYDAY=MO;BYWEEKNO=1',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored the ordinals in BYDAY=1MO: ordinals are not allowed together with BYWEEKNO', null]],
        ];

        yield 'BYDAY ordinals with MONTHLY are allowed' => ['FREQ=MONTHLY;BYDAY=1MO,-1FR', 'FREQ=MONTHLY;BYDAY=1MO,-1FR', []];
        yield 'BYDAY ordinals with YEARLY are allowed' => ['FREQ=YEARLY;BYDAY=20MO', 'FREQ=YEARLY;BYDAY=20MO', []];
        yield 'BYDAY without ordinals with WEEKLY' => ['FREQ=WEEKLY;BYDAY=MO,MO', 'FREQ=WEEKLY;BYDAY=MO,MO', []];
        yield 'BYDAY without ordinals with BYWEEKNO' => ['FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO', 'FREQ=YEARLY;BYDAY=MO;BYWEEKNO=1', []];

        yield 'BYWEEKNO is dropped before the ordinals are checked' => [
            'FREQ=MONTHLY;BYWEEKNO=1;BYDAY=1MO',
            'FREQ=MONTHLY;BYDAY=1MO',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYWEEKNO=1: BYWEEKNO is allowed only with FREQ=YEARLY', null]],
        ];

        yield 'BYWEEKNO and ordinals with DAILY' => [
            'FREQ=DAILY;BYWEEKNO=1;BYDAY=1MO',
            'FREQ=DAILY;BYDAY=MO',
            [
                [ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYWEEKNO=1: BYWEEKNO is allowed only with FREQ=YEARLY', null],
                [ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored the ordinals in BYDAY=1MO: ordinals are allowed only with FREQ=MONTHLY or FREQ=YEARLY', null],
            ],
        ];

        yield 'BYSETPOS alone' => [
            'FREQ=MONTHLY;BYSETPOS=1,-1',
            'FREQ=MONTHLY',
            [[ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYSETPOS=1,-1: BYSETPOS must be used together with another BYxxx rule part', null]],
        ];

        yield 'BYSETPOS whose only partner is dropped' => [
            'FREQ=WEEKLY;BYMONTHDAY=1;BYSETPOS=1',
            'FREQ=WEEKLY',
            [
                [ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYMONTHDAY=1: BYMONTHDAY is not allowed with FREQ=WEEKLY', null],
                [ParseWarningCode::ForbiddenRulePartIgnored, 'Ignored BYSETPOS=1: BYSETPOS must be used together with another BYxxx rule part', null],
            ],
        ];

        yield 'BYSETPOS with BYSECOND' => ['FREQ=MINUTELY;BYSECOND=1;BYSETPOS=1', 'FREQ=MINUTELY;BYSECOND=1;BYSETPOS=1', []];
        yield 'BYSETPOS with BYMINUTE' => ['FREQ=HOURLY;BYMINUTE=1;BYSETPOS=1', 'FREQ=HOURLY;BYMINUTE=1;BYSETPOS=1', []];
        yield 'BYSETPOS with BYHOUR' => ['FREQ=DAILY;BYHOUR=1;BYSETPOS=1', 'FREQ=DAILY;BYHOUR=1;BYSETPOS=1', []];
        yield 'BYSETPOS with BYDAY' => ['FREQ=MONTHLY;BYDAY=MO;BYSETPOS=1', 'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=1', []];
        yield 'BYSETPOS with BYMONTHDAY' => ['FREQ=MONTHLY;BYMONTHDAY=1;BYSETPOS=1', 'FREQ=MONTHLY;BYMONTHDAY=1;BYSETPOS=1', []];
        yield 'BYSETPOS with BYYEARDAY' => ['FREQ=YEARLY;BYYEARDAY=1;BYSETPOS=1', 'FREQ=YEARLY;BYYEARDAY=1;BYSETPOS=1', []];
        yield 'BYSETPOS with BYWEEKNO' => ['FREQ=YEARLY;BYWEEKNO=1;BYSETPOS=1', 'FREQ=YEARLY;BYWEEKNO=1;BYSETPOS=1', []];
        yield 'BYSETPOS with BYMONTH' => ['FREQ=YEARLY;BYMONTH=1;BYSETPOS=1', 'FREQ=YEARLY;BYMONTH=1;BYSETPOS=1', []];

        yield 'COUNT with UNTIL' => [
            'FREQ=DAILY;COUNT=05;UNTIL=20260101',
            'FREQ=DAILY;UNTIL=20260101',
            [[ParseWarningCode::CountWithUntilCountIgnored, 'Ignored COUNT=5, because the rule also has UNTIL', null]],
        ];

        yield 'negative COUNT (Office 365)' => [
            'FREQ=WEEKLY;COUNT=-1;BYDAY=FR',
            'FREQ=WEEKLY;BYDAY=FR',
            [[ParseWarningCode::InvalidCountIgnored, 'Ignored COUNT=-1: COUNT must be a positive integer', null]],
        ];

        yield 'COUNT zero' => [
            'FREQ=DAILY;COUNT=0',
            'FREQ=DAILY',
            [[ParseWarningCode::InvalidCountIgnored, 'Ignored COUNT=0: COUNT must be a positive integer', null]],
        ];

        yield 'negative COUNT with UNTIL' => [
            'FREQ=DAILY;COUNT=-1;UNTIL=20260101',
            'FREQ=DAILY;UNTIL=20260101',
            [[ParseWarningCode::InvalidCountIgnored, 'Ignored COUNT=-1: COUNT must be a positive integer', null]],
        ];

        yield 'SKIP without RSCALE' => [
            'FREQ=YEARLY;SKIP=FORWARD',
            'FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=FORWARD',
            [[ParseWarningCode::SkipWithoutCalendarScale, 'SKIP requires RSCALE: assumed RSCALE=GREGORIAN', null]],
        ];

        yield 'SKIP=OMIT without RSCALE' => [
            'FREQ=YEARLY;SKIP=OMIT',
            'FREQ=YEARLY;RSCALE=GREGORIAN',
            [[ParseWarningCode::SkipWithoutCalendarScale, 'SKIP requires RSCALE: assumed RSCALE=GREGORIAN', null]],
        ];

        yield 'DTSTART inside the rule (rrule.js)' => [
            'DTSTART=20120201T093000Z;FREQ=WEEKLY;INTERVAL=5',
            'FREQ=WEEKLY;INTERVAL=5',
            [[ParseWarningCode::StartInsideRule, 'Ignored DTSTART=20120201T093000Z inside the rule, because a rule has no start (parseRecurrence() uses it)', null]],
        ];

        yield 'invalid DTSTART inside the rule is not parsed' => [
            'FREQ=DAILY;dtstart=garbage;DTSTART=20260101',
            'FREQ=DAILY',
            [
                [ParseWarningCode::DuplicateRulePartIgnored, 'Ignored the duplicate rule part "DTSTART=20260101"', null],
                [ParseWarningCode::StartInsideRule, 'Ignored DTSTART=garbage inside the rule, because a rule has no start (parseRecurrence() uses it)', null],
            ],
        ];

        yield 'parts that need the start are kept without it' => ['FREQ=DAILY;UNTIL=20260101;BYHOUR=9', 'FREQ=DAILY;UNTIL=20260101;BYHOUR=9', []];
    }

}
