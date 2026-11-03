<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\DtStartPolicy;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\Skip;
use ShipMonk\RRuleTests\Oracle\Generator\GeneratedRecurrence;
use ShipMonk\RRuleTests\Oracle\Generator\RandomRuleGenerator;
use ShipMonk\RRuleTests\Oracle\Libical\LibicalException;
use ShipMonk\RRuleTests\Oracle\Libical\LibicalOracle;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function file_put_contents;
use function getenv;
use function in_array;
use function is_string;
use function json_encode;
use function min;
use function sprintf;
use function str_contains;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Compares the engine with libical 4 (the reference C implementation, built without ICU, so sub-daily rules
 * step in wall-clock time like this library) on random rules. Zoned starts are compared as UTC instants, which
 * also checks the DST resolution.
 *
 * A mismatch passes only when it falls in a known category, where this library follows the RFC text or
 * JSCalendar 2.0 and libical does not (see the README "Semantics" table).
 */
#[CoversNothing]
final class LibicalDifferentialTest extends TestCase
{

    private const CASES = 2000;
    private const LIMIT = 25;

    public function testEngineAgreesWithLibical(): void
    {
        try {
            $oracle = new LibicalOracle(LibicalOracle::DEFAULT_LIBRARY_PATH);
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $generator = new RandomRuleGenerator(20_261_103);
        $known = [];
        $unexplained = [];
        $compared = 0;

        for ($i = 0; $i < self::CASES; $i++) {
            $case = $generator->next();
            $ours = self::expandWithEngine($case);

            try {
                $raw = $case->dtStart->kind === DtStartKind::Zoned
                    ? $oracle->expandToUtc($case->dtStart, $case->rrule, self::LIMIT)
                    : $oracle->expand($case->dtStart, $case->rrule, self::LIMIT);
                $theirs = array_values(array_unique($raw));
            } catch (LibicalException $e) {
                $category = self::explainLibicalError($e, $ours);

                if ($category === null) {
                    $unexplained[] = ['case' => $case->toString(), 'libical' => $e->libicalError, 'ours' => $ours];
                } else {
                    $known[$category] = ($known[$category] ?? 0) + 1;
                }

                continue;
            }

            $length = min(count($ours), count($theirs));
            $comparable = count($ours) === count($theirs) || $length === self::LIMIT || count($theirs) < self::LIMIT;
            $compared++;

            if (array_slice($ours, 0, $comparable ? null : $length) === array_slice($theirs, 0, $comparable ? null : $length)) {
                continue;
            }

            $category = self::explainDifference($case)
                ?? (ReferenceArbiter::agreesWithEngine($case, $ours, $theirs, self::LIMIT) ? 'libical differs from the JSCalendar reference implementation, which agrees with the engine' : null)
                ?? (count($raw) !== count($theirs) && $case->rule->end instanceof Count && array_slice($ours, 0, count($theirs)) === $theirs
                    ? 'S5: COUNT counts distinct instants; libical counts a repeated instant in a DST gap twice'
                    : null);

            if ($category === null) {
                $unexplained[] = ['case' => $case->toString(), 'libical' => $theirs, 'ours' => $ours];
            } else {
                $known[$category] = ($known[$category] ?? 0) + 1;
            }
        }

        $report = getenv('ORACLE_REPORT');

        if (is_string($report) && $report !== '') {
            file_put_contents($report, json_encode(['known' => $known, 'unexplained' => $unexplained], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        self::assertSame(
            [],
            array_slice($unexplained, 0, 10),
            sprintf("%d unexplained of %d compared; known differences: %s\n", count($unexplained), $compared, json_encode($known, JSON_THROW_ON_ERROR))
            . json_encode(array_slice($unexplained, 0, 10), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        self::assertGreaterThan(self::CASES * 0.9, $compared);
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

    /**
     * @param list<string> $ours
     */
    private static function explainLibicalError(
        LibicalException $exception,
        array $ours,
    ): ?string
    {
        if (str_contains($exception->libicalError, 'UNIMPLEMENTED')) {
            return 'libical does not implement this combination (S17)';
        }

        if (str_contains($exception->libicalError, 'MALFORMEDDATA') && $ours === []) {
            return 'libical rejects a valid rule that never matches';
        }

        return null;
    }

    private static function explainDifference(GeneratedRecurrence $case): ?string
    {
        $rule = $case->rule;

        if ($rule->frequency === Frequency::Yearly && $rule->byWeekNo !== [] && $rule->interval > 1) {
            return 'S13: INTERVAL with BYWEEKNO counts calendar years';
        }

        if ($rule->skip !== Skip::Omit && ($rule->byYearDay !== [] || $rule->byWeekNo !== [])) {
            return 'S2: SKIP does not adjust BYYEARDAY or BYWEEKNO';
        }

        if (in_array(60, $rule->bySecond, true)) {
            return 'S18: BYSECOND=60 matches nothing';
        }

        if ($rule->byWeekNo !== [] && in_array(-53, $rule->byWeekNo, true)) {
            return 'S12: BYWEEKNO=-53 is week 1 of a year with 53 weeks; libical takes week 53';
        }

        return null;
    }

}
