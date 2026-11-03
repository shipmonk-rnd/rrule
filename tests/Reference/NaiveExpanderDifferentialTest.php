<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Reference;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\CalendarScale;
use ShipMonk\RRule\Count;
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
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function str_ends_with;
use const SORT_REGULAR;

/**
 * Compares the engine with {@see NaiveExpander} on seeded random rules: all frequencies, all BYxxx parts,
 * negative values, ordinals, BYSETPOS, WKST, INTERVAL, COUNT, UNTIL and RFC 7529 SKIP.
 */
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
final class NaiveExpanderDifferentialTest extends TestCase
{

    private const CASES = 400;

    #[DataProvider('provideSeeds')]
    public function testEngineMatchesNaiveExpansion(int $seed): void
    {
        $random = new XorShiftRandom($seed);
        [$rule, $start] = self::generateCase($random);
        $subDaily = !in_array($rule->frequency, [Frequency::Daily, Frequency::Weekly, Frequency::Monthly, Frequency::Yearly], true);
        [$expected, $completeBefore] = NaiveExpander::expand($rule, $start, true, 25, $start->year + ($subDaily ? 1 : 40), $subDaily ? 400 : 5000);

        $actual = [];

        foreach (Recurrence::of($start, $rule) as $occurrence) {
            $value = $occurrence->toString();

            if ($completeBefore !== null && $value >= $completeBefore) {
                break;
            }

            $actual[] = $value;

            if (count($actual) >= count($expected) && $completeBefore !== null && str_ends_with($completeBefore, '!')) {
                break;
            }
        }

        self::assertSame($expected, $actual, 'RRULE:' . self::describe($rule) . ' DTSTART:' . $start->toString() . ' complete before ' . ($completeBefore ?? '-'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideSeeds(): iterable
    {
        for ($seed = 1; $seed <= self::CASES; $seed++) {
            yield "seed $seed" => [$seed];
        }
    }

    /**
     * @return array{RecurrenceRule, FloatingDateTime}
     */
    private static function generateCase(XorShiftRandom $random): array
    {
        $frequency = $random->pick([Frequency::Yearly, Frequency::Yearly, Frequency::Monthly, Frequency::Monthly, Frequency::Weekly, Frequency::Daily, Frequency::Hourly, Frequency::Minutely, Frequency::Secondly]);
        $subDaily = in_array($frequency, [Frequency::Hourly, Frequency::Minutely, Frequency::Secondly], true);
        $year = $random->between(1995, 2030);
        $month = $random->between(1, 12);
        $daysInMonth = (int) (new DateTimeImmutable("$year-$month-01"))->format('t');
        $start = FloatingDateTime::of(
            $year,
            $month,
            $random->chance(25) ? $daysInMonth - $random->between(0, 2) : $random->between(1, 28),
            $random->between(0, 23),
            $random->pick([0, 0, 15, 30, $random->between(0, 59)]),
            $random->pick([0, 0, 0, $random->between(0, 59)]),
        );

        $byMonth = $random->chance(30) ? self::list($random, 1, 3, static fn () => $random->between(1, 12)) : [];
        $byWeekNo = $frequency === Frequency::Yearly && $random->chance(25)
            ? self::list($random, 1, 2, static fn () => $random->pick([1, 2, 10, 20, 26, 52, 53, -1, -2, -53, $random->between(1, 53)]))
            : [];
        $byYearDay = in_array($frequency, [Frequency::Yearly, Frequency::Hourly, Frequency::Minutely, Frequency::Secondly], true) && $random->chance(15)
            ? self::list($random, 1, 3, static fn () => $random->pick([1, 60, 100, 200, 365, 366, -1, -366, $random->between(1, 366), -$random->between(1, 366)]))
            : [];
        $byMonthDay = $frequency !== Frequency::Weekly && $random->chance(35)
            ? self::list($random, 1, 3, static fn () => $random->pick([1, 13, 15, 28, 29, 30, 31, -1, -2, -31, $random->between(1, 31), -$random->between(1, 31)]))
            : [];
        $ordinalsAllowed = ($frequency === Frequency::Monthly || $frequency === Frequency::Yearly) && $byWeekNo === [];
        $byDay = $random->chance(45) ? self::list($random, 1, 3, static function () use ($random, $ordinalsAllowed, $frequency, $byMonth): WeekdayNum {
            $weekday = $random->pick(Weekday::cases());

            if (!$ordinalsAllowed || $random->chance(50)) {
                return WeekdayNum::every($weekday);
            }

            $max = $frequency === Frequency::Yearly && $byMonth === [] ? 53 : 5;
            $ordinal = $random->between(1, $max);

            return WeekdayNum::of($weekday, $random->chance(30) ? -$ordinal : $ordinal);
        }) : [];
        $byHour = $random->chance($subDaily ? 50 : 20) ? self::list($random, 1, 3, static fn () => $random->between(0, 23)) : [];
        $byMinute = $random->chance($subDaily ? 50 : 15) ? self::list($random, 1, 3, static fn () => $random->pick([0, 15, 30, 45, $random->between(0, 59)])) : [];
        $bySecond = $random->chance($frequency === Frequency::Secondly ? 70 : 10) ? self::list($random, 1, 3, static fn () => $random->pick([0, 30, 59, 60, $random->between(0, 59)])) : [];
        $hasOtherByPart = $byMonth !== [] || $byWeekNo !== [] || $byYearDay !== [] || $byMonthDay !== [] || $byDay !== [] || $byHour !== [] || $byMinute !== [] || $bySecond !== [];
        $bySetPos = $hasOtherByPart && $random->chance(20) ? self::list($random, 1, 2, static fn () => $random->pick([1, 2, 3, -1, -2, $random->between(1, 10)])) : [];
        $skip = ($frequency === Frequency::Monthly || $frequency === Frequency::Yearly) && $random->chance(15)
            ? $random->pick([Skip::Forward, Skip::Backward])
            : Skip::Omit;
        $end = null;

        if ($random->chance(30)) {
            $end = Count::of($random->between(1, 20));
        } elseif ($random->chance(15)) {
            $end = Until::floating(FloatingDateTime::of($start->year + $random->between(0, 3), $random->between(1, 12), $random->between(1, 28), $random->between(0, 23)));
        }

        $rule = new RecurrenceRule(
            frequency: $frequency,
            interval: $random->pick([1, 1, 1, 2, 3, $random->between(1, 7), 12]),
            end: $end,
            bySecond: $bySecond,
            byMinute: $byMinute,
            byHour: $byHour,
            byDay: $byDay,
            byMonthDay: $byMonthDay,
            byYearDay: $byYearDay,
            byWeekNo: $byWeekNo,
            byMonth: $byMonth,
            bySetPos: $bySetPos,
            weekStart: $random->chance(25) ? $random->pick(Weekday::cases()) : Weekday::Monday,
            calendarScale: $skip === Skip::Omit ? null : CalendarScale::Gregorian,
            skip: $skip,
        );

        return [$rule, $start];
    }

    /**
     * @param callable(): V $generate
     * @return list<V>
     *
     * @template V
     */
    private static function list(
        XorShiftRandom $random,
        int $min,
        int $max,
        callable $generate,
    ): array
    {
        $values = [];

        for ($i = $random->between($min, $max); $i > 0; $i--) {
            $values[] = $generate();
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    private static function describe(RecurrenceRule $rule): string
    {
        $parts = ['FREQ=' . $rule->frequency->value, 'INTERVAL=' . $rule->interval];

        if ($rule->end instanceof Count) {
            $parts[] = 'COUNT=' . $rule->end->value;
        }

        if ($rule->end instanceof Until && $rule->end->value instanceof FloatingDateTime) {
            $parts[] = 'UNTIL=' . $rule->end->value->toString();
        }

        foreach (['BYSECOND' => $rule->bySecond, 'BYMINUTE' => $rule->byMinute, 'BYHOUR' => $rule->byHour, 'BYMONTHDAY' => $rule->byMonthDay, 'BYYEARDAY' => $rule->byYearDay, 'BYWEEKNO' => $rule->byWeekNo, 'BYMONTH' => $rule->byMonth, 'BYSETPOS' => $rule->bySetPos] as $name => $values) {
            if ($values !== []) {
                $parts[] = $name . '=' . implode(',', $values);
            }
        }

        if ($rule->byDay !== []) {
            $parts[] = 'BYDAY=' . implode(',', array_map(static fn (WeekdayNum $day): string => $day->toString(), $rule->byDay));
        }

        $parts[] = 'WKST=' . $rule->weekStart->value;
        $parts[] = 'SKIP=' . $rule->skip->value;

        return implode(';', $parts);
    }

}
