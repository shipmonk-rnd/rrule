<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\DtStartPolicy;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\Skip;
use ShipMonk\RRuleTests\Oracle\Generator\GeneratedRecurrence;
use ShipMonk\RRuleTests\Oracle\Generator\RandomRuleGenerator;
use ShipMonk\RRuleTests\Oracle\Python\PythonOracle;
use function array_map;
use function array_slice;
use function count;
use function in_array;
use function is_string;
use function json_encode;
use function sprintf;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Compares the engine with python-dateutil 4.0 on random rules with a date, floating or UTC start (zoned starts
 * are compared with libical). dateutil is a second, independent family of implementations (rrule.js, rrule-go and
 * rust-rrule descend from it). Disagreements are decided by the JSCalendar reference implementation.
 */
#[CoversNothing]
final class DateutilDifferentialTest extends TestCase
{

    private const CASES = 2000;
    private const LIMIT = 25;

    public function testEngineAgreesWithDateutil(): void
    {
        try {
            $oracle = PythonOracle::fromEnvironment();
            $oracle->expandWithDateutil([['recurrence' => "DTSTART:20260101T000000\nRRULE:FREQ=DAILY;COUNT=1", 'limit' => 1]]);
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $generator = new RandomRuleGenerator(4242);
        $cases = [];

        while (count($cases) < self::CASES) {
            $case = $generator->next();

            if ($case->dtStart->kind !== DtStartKind::Zoned) {
                $cases[] = $case;
            }
        }

        $results = $oracle->expandWithDateutil(array_map(
            static fn (GeneratedRecurrence $case): array => ['recurrence' => $case->toRecurrenceText(), 'limit' => self::LIMIT],
            $cases,
        ));
        $known = [];
        $unexplained = [];

        foreach ($cases as $index => $case) {
            $theirs = $results[$index] ?? 'missing result';
            $ours = self::expandWithEngine($case);

            if ($ours === $theirs) {
                continue;
            }

            $category = self::explain($case, $theirs, $ours);

            if ($category === null) {
                $unexplained[] = ['case' => $case->toString(), 'dateutil' => $theirs, 'ours' => $ours];
            } else {
                $known[$category] = ($known[$category] ?? 0) + 1;
            }
        }

        self::assertSame(
            [],
            array_slice($unexplained, 0, 10),
            sprintf("%d unexplained of %d; known differences: %s\n", count($unexplained), count($cases), json_encode($known, JSON_THROW_ON_ERROR))
            . json_encode(array_slice($unexplained, 0, 10), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<string>|string $theirs
     * @param list<string>        $ours
     */
    private static function explain(
        GeneratedRecurrence $case,
        array|string $theirs,
        array $ours,
    ): ?string
    {
        $rule = $case->rule;

        if ($rule->calendarScale !== null || $rule->skip !== Skip::Omit) {
            return 'dateutil does not support RFC 7529 (RSCALE, SKIP)';
        }

        if (in_array(60, $rule->bySecond, true)) {
            return 'S18: BYSECOND=60 matches nothing; dateutil rejects it';
        }

        if (is_string($theirs)) {
            return null;
        }

        if (ReferenceArbiter::agreesWithEngine($case, $ours, $theirs, self::LIMIT)) {
            return 'dateutil differs from the JSCalendar reference implementation, which agrees with the engine';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function expandWithEngine(GeneratedRecurrence $case): array
    {
        $recurrence = Recurrence::fromString($case->toRecurrenceText())->withDtStartPolicy(DtStartPolicy::OnlyIfMatching);
        $result = [];

        foreach ($recurrence->take(self::LIMIT) as $occurrence) {
            $result[] = match (true) {
                $occurrence instanceof CalendarDate => sprintf('%04d%02d%02d', $occurrence->year, $occurrence->month, $occurrence->day),
                $occurrence instanceof FloatingDateTime => sprintf('%04d%02d%02dT%02d%02d%02d', $occurrence->year, $occurrence->month, $occurrence->day, $occurrence->hour, $occurrence->minute, $occurrence->second),
                $occurrence instanceof DateTimeImmutable => $occurrence->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            };
        }

        return $result;
    }

}
