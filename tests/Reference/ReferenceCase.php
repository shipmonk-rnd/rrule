<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Reference;

use DateTimeImmutable;
use LogicException;
use ShipMonk\RRule\CalendarScale;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\Exception\InvalidRecurrenceRuleException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_is_list;
use function array_map;
use function array_unique;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function range;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SORT_REGULAR;

/**
 * A seeded random rule with its {@see NaiveExpander} result.
 *
 * The naive expansion takes minutes, so tests/Reference/data/naive-snapshot.json stores it.
 * {@see NaiveExpanderDifferentialTest} compares the engine with the snapshot in the default test suite.
 * {@see NaiveExpanderSnapshotTest} compares the snapshot with a new naive expansion in the reference test suite.
 */
final class ReferenceCase
{

    public const SEEDS = 1000;

    private const SNAPSHOT_PATH = __DIR__ . '/data/naive-snapshot.json';

    /**
     * @param list<string> $expected       occurrences as Y-m-d\TH:i:s
     * @param string|null  $completeBefore the exclusive end of the time range that $expected is complete for
     *                                     (with a "!" suffix: complete up to the last occurrence); null when complete
     */
    public function __construct(
        public readonly int $seed,
        public readonly string $description,
        public readonly array $expected,
        public readonly ?string $completeBefore,
    )
    {
    }

    /**
     * @throws InvalidRecurrenceRuleException
     * @throws InvalidValueException
     */
    public static function expandNaively(int $seed): self
    {
        [$rule, $start] = self::generate($seed);
        $subDaily = !in_array($rule->frequency, [Frequency::Daily, Frequency::Weekly, Frequency::Monthly, Frequency::Yearly], true);
        [$expected, $completeBefore] = NaiveExpander::expand($rule, $start, true, 25, $start->year + ($subDaily ? 1 : 40), $subDaily ? 400 : 5000);

        return new self($seed, self::describe($rule, $start), $expected, $completeBefore);
    }

    /**
     * @return array{RecurrenceRule, FloatingDateTime}
     *
     * @throws InvalidRecurrenceRuleException
     * @throws InvalidValueException
     */
    public static function generate(int $seed): array
    {
        $random = new XorShiftRandom($seed);
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

        $byMonth = $random->chance(30) ? self::list($random, 1, 3, static fn () => $random->pick(range(1, 12))) : [];
        $byWeekNo = $frequency === Frequency::Yearly && $random->chance(25)
            ? self::list($random, 1, 2, static fn () => $random->pick([1, 2, 10, 20, 26, 52, 53, -1, -2, -53, $random->pick(range(1, 53))]))
            : [];
        $byYearDay = in_array($frequency, [Frequency::Yearly, Frequency::Hourly, Frequency::Minutely, Frequency::Secondly], true) && $random->chance(15)
            ? self::list($random, 1, 3, static fn () => $random->pick([1, 60, 100, 200, 365, 366, -1, -366, $random->pick(range(1, 366)), -$random->pick(range(1, 366))]))
            : [];
        $byMonthDay = $frequency !== Frequency::Weekly && $random->chance(35)
            ? self::list($random, 1, 3, static fn () => $random->pick([1, 13, 15, 28, 29, 30, 31, -1, -2, -31, $random->pick(range(1, 31)), -$random->pick(range(1, 31))]))
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
        $byHour = $random->chance($subDaily ? 50 : 20) ? self::list($random, 1, 3, static fn () => $random->pick(range(0, 23))) : [];
        $byMinute = $random->chance($subDaily ? 50 : 15) ? self::list($random, 1, 3, static fn () => $random->pick([0, 15, 30, 45, $random->pick(range(0, 59))])) : [];
        $bySecond = $random->chance($frequency === Frequency::Secondly ? 70 : 10) ? self::list($random, 1, 3, static fn () => $random->pick([0, 30, 59, 60, $random->pick(range(0, 59))])) : [];
        $hasOtherByPart = $byMonth !== [] || $byWeekNo !== [] || $byYearDay !== [] || $byMonthDay !== [] || $byDay !== [] || $byHour !== [] || $byMinute !== [] || $bySecond !== [];
        $bySetPos = $hasOtherByPart && $random->chance(20) ? self::list($random, 1, 2, static fn () => $random->pick([1, 2, 3, -1, -2, $random->pick(range(1, 10))])) : [];
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
            interval: $random->pick([1, 1, 1, 2, 3, $random->pick(range(1, 7)), 12]),
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

    public static function describe(
        RecurrenceRule $rule,
        FloatingDateTime $start,
    ): string
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

        return 'DTSTART:' . $start->toString() . ' RRULE:' . implode(';', $parts);
    }

    /**
     * @return array<int, self> seed => case
     */
    public static function loadSnapshot(): array
    {
        $data = json_decode((string) file_get_contents(self::SNAPSHOT_PATH), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !array_is_list($data)) {
            throw new LogicException('The reference snapshot must be a list');
        }

        $cases = [];

        foreach ($data as $item) {
            $case = self::fromArray(is_array($item) ? $item : throw new LogicException('Invalid reference snapshot entry'));
            $cases[$case->seed] = $case;
        }

        return $cases;
    }

    /**
     * @param list<self> $cases
     */
    public static function saveSnapshot(array $cases): void
    {
        $lines = array_map(static fn (self $case): string => json_encode($case->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $cases);
        file_put_contents(self::SNAPSHOT_PATH, "[\n" . implode(",\n", $lines) . "\n]\n");
    }

    /**
     * @return array{seed: int, case: string, expected: list<string>, completeBefore: string|null}
     */
    public function toArray(): array
    {
        return ['seed' => $this->seed, 'case' => $this->description, 'expected' => $this->expected, 'completeBefore' => $this->completeBefore];
    }

    /**
     * @param array<mixed> $data
     */
    private static function fromArray(array $data): self
    {
        $expected = $data['expected'] ?? null;
        $completeBefore = $data['completeBefore'] ?? null;
        $values = [];

        foreach (is_array($expected) ? $expected : throw new LogicException('Reference field "expected" must be a list') as $value) {
            $values[] = is_string($value) ? $value : throw new LogicException('Reference field "expected" must contain strings');
        }

        return new self(
            is_int($data['seed'] ?? null) ? $data['seed'] : throw new LogicException('Reference field "seed" must be an integer'),
            is_string($data['case'] ?? null) ? $data['case'] : throw new LogicException('Reference field "case" must be a string'),
            $values,
            $completeBefore === null || is_string($completeBefore) ? $completeBefore : throw new LogicException('Reference field "completeBefore" must be a string or null'),
        );
    }

    /**
     * @param callable(): V $generate
     * @return list<V>
     *
     * @template V
     *
     * @param-immediately-invoked-callable $generate
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

}
