<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Reference;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_flip;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function intdiv;
use function ksort;
use function range;
use function sort;
use const PHP_INT_MAX;

/**
 * A slow, literal implementation of the JSCalendar 2.0 recurrence expansion algorithm (section 4.3.3.1) for a
 * floating start, used as an independent reference in tests. It shares no code with the library engine: calendar math
 * goes through PHP's DateTime in UTC, sub-daily periods are scanned second by second, and there are no jumps,
 * caches or termination proofs (it stops at a given year).
 */
final class NaiveExpander
{

    private const FORMAT = 'Y-m-d\TH:i:s';

    /**
     * Expands until COUNT, UNTIL, $maxResults, the end of $lastYear or $maxPeriods periods, whichever comes first.
     *
     * @return array{list<string>, string|null} occurrences as Y-m-d\TH:i:s, and the exclusive end of the time range
     *                                           the result is complete for (null: complete, nothing was cut)
     */
    public static function expand(
        RecurrenceRule $rule,
        FloatingDateTime $start,
        bool $startCounts,
        int $maxResults,
        int $lastYear,
        int $maxPeriods = PHP_INT_MAX,
    ): array
    {
        $utc = new DateTimeZone('UTC');
        $startTime = new DateTimeImmutable($start->toString(), $utc);
        $parts = self::addImpliedParts($rule, $startTime);
        $results = [];
        $count = $rule->end instanceof Count ? $rule->end->value : null;
        $until = $rule->end instanceof Until && $rule->end->value instanceof FloatingDateTime ? $rule->end->value->toString() : null;
        $startString = $startTime->format(self::FORMAT);

        if ($startCounts) {
            $results[] = $startString;

            if ($count === 1) {
                return [$results, null];
            }
        }

        for ($period = 0;; $period++) {
            [$periodStart, $periodEnd] = self::getPeriod($rule, $startTime, $period);

            if ((int) $periodStart->format('Y') > $lastYear) {
                return [$results, ($lastYear + 1) . '-01-01T00:00:00'];
            }

            if ($period >= $maxPeriods) {
                return [$results, $periodStart->format(self::FORMAT)];
            }

            $candidates = self::filterCandidates($rule, $parts, self::generateCandidates($rule, $parts, $periodStart, $periodEnd));
            $candidates = self::selectSetPositions($candidates, $rule->bySetPos);

            foreach ($candidates as $candidate) {
                if ($candidate < $startString || in_array($candidate, $results, true)) {
                    continue;
                }

                if ($until !== null && $candidate > $until) {
                    return [$results, null];
                }

                if ((int) $candidate >= $lastYear + 1) {
                    return [$results, ($lastYear + 1) . '-01-01T00:00:00'];
                }

                $results[] = $candidate;

                if ($count !== null && count($results) >= $count) {
                    return [$results, null];
                }

                if (count($results) >= $maxResults) {
                    return [$results, $candidate . '!'];
                }
            }
        }
    }

    /**
     * The JSCalendar "implicitly added" parts.
     *
     * @return array{bySecond: list<int>, byMinute: list<int>, byHour: list<int>, byDay: list<array{int, int|null}>, byMonthDay: list<int>, byMonth: list<int>}
     */
    private static function addImpliedParts(
        RecurrenceRule $rule,
        DateTimeImmutable $start,
    ): array
    {
        $frequency = $rule->frequency;
        $bySecond = $rule->bySecond;
        $byMinute = $rule->byMinute;
        $byHour = $rule->byHour;
        $byDay = array_map(static fn (WeekdayNum $weekdayNum): array => [$weekdayNum->weekday->getIsoNumber(), $weekdayNum->ordinal], $rule->byDay);
        $byMonthDay = $rule->byMonthDay;
        $byMonth = $rule->byMonth;
        $startWeekday = (int) $start->format('N');

        if ($frequency !== Frequency::Secondly && $bySecond === []) {
            $bySecond = [(int) $start->format('s')];
        }

        if ($frequency !== Frequency::Secondly && $frequency !== Frequency::Minutely && $byMinute === []) {
            $byMinute = [(int) $start->format('i')];
        }

        if ($frequency !== Frequency::Secondly && $frequency !== Frequency::Minutely && $frequency !== Frequency::Hourly && $byHour === []) {
            $byHour = [(int) $start->format('G')];
        }

        if ($frequency === Frequency::Weekly && $byDay === []) {
            $byDay = [[$startWeekday, null]];
        }

        if ($frequency === Frequency::Monthly && $byDay === [] && $byMonthDay === []) {
            $byMonthDay = [(int) $start->format('j')];
        }

        if ($frequency === Frequency::Yearly && $rule->byYearDay === []) {
            $originalByDay = $byDay;
            $originalByMonthDay = $byMonthDay;

            if ($byMonth === [] && $rule->byWeekNo === [] && ($originalByMonthDay !== [] || $originalByDay === [])) {
                $byMonth = [(int) $start->format('n')];
            }

            if ($originalByMonthDay === [] && $rule->byWeekNo === [] && $originalByDay === []) {
                $byMonthDay = [(int) $start->format('j')];
            }

            if ($rule->byWeekNo !== [] && $originalByMonthDay === [] && $originalByDay === []) {
                $byDay = [[$startWeekday, null]];
            }
        }

        return [
            'bySecond' => $bySecond,
            'byMinute' => $byMinute,
            'byHour' => $byHour,
            'byDay' => $byDay,
            'byMonthDay' => $byMonthDay,
            'byMonth' => $byMonth,
        ];
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable} [start, end)
     */
    private static function getPeriod(
        RecurrenceRule $rule,
        DateTimeImmutable $start,
        int $period,
    ): array
    {
        $steps = $period * $rule->interval;
        $midnight = $start->setTime(0, 0);

        switch ($rule->frequency) {
            case Frequency::Yearly:
                $year = (int) $start->format('Y') + $steps;
                $periodStart = $midnight->setDate($year, 1, 1);

                return [$periodStart, $periodStart->setDate($year + 1, 1, 1)];

            case Frequency::Monthly:
                $monthIndex = (int) $start->format('Y') * 12 + (int) $start->format('n') - 1 + $steps;
                $periodStart = $midnight->setDate(intdiv($monthIndex, 12), $monthIndex % 12 + 1, 1);

                return [$periodStart, self::shift($periodStart, 'first day of next month')];

            case Frequency::Weekly:
                $weekStartIso = $rule->weekStart->getIsoNumber();
                $back = ((int) $start->format('N') - $weekStartIso + 7) % 7;
                $periodStart = self::shift(self::shift($midnight, "-$back days"), '+' . (7 * $steps) . ' days');

                return [$periodStart, self::shift($periodStart, '+7 days')];

            case Frequency::Daily:
                $periodStart = self::shift($midnight, "+$steps days");

                return [$periodStart, self::shift($periodStart, '+1 day')];

            case Frequency::Hourly:
                $periodStart = self::shift($start->setTime((int) $start->format('G'), 0), "+$steps hours");

                return [$periodStart, self::shift($periodStart, '+1 hour')];

            case Frequency::Minutely:
                $periodStart = self::shift($start->setTime((int) $start->format('G'), (int) $start->format('i')), "+$steps minutes");

                return [$periodStart, self::shift($periodStart, '+1 minute')];

            case Frequency::Secondly:
                $periodStart = self::shift($start, "+$steps seconds");

                return [$periodStart, self::shift($periodStart, '+1 second')];
        }
    }

    /**
     * Candidates as [year, month, nominal day (may be invalid with SKIP), time of day in seconds].
     *
     * @param array{bySecond: list<int>, byMinute: list<int>, byHour: list<int>, byDay: list<array{int, int|null}>, byMonthDay: list<int>, byMonth: list<int>} $parts
     * @return list<array{int, int, int, int}>
     */
    private static function generateCandidates(
        RecurrenceRule $rule,
        array $parts,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): array
    {
        $candidates = [];
        $subDaily = in_array($rule->frequency, [Frequency::Hourly, Frequency::Minutely, Frequency::Secondly], true);

        if ($subDaily) {
            for ($time = $periodStart; $time < $periodEnd; $time = self::shift($time, '+1 second')) {
                $candidates[] = [(int) $time->format('Y'), (int) $time->format('n'), (int) $time->format('j'), (int) $time->format('G') * 3600 + (int) $time->format('i') * 60 + (int) $time->format('s')];
            }

            return $candidates;
        }

        $times = [];

        foreach ($parts['byHour'] as $hour) {
            foreach ($parts['byMinute'] as $minute) {
                foreach ($parts['bySecond'] as $second) {
                    $times[] = $hour * 3600 + $minute * 60 + $second;
                }
            }
        }

        $skipActive = $rule->skip !== Skip::Omit && $parts['byMonthDay'] !== [] && in_array($rule->frequency, [Frequency::Monthly, Frequency::Yearly], true);

        for ($day = $periodStart; $day < $periodEnd; $day = self::shift($day, '+1 day')) {
            $year = (int) $day->format('Y');
            $month = (int) $day->format('n');
            $dayOfMonth = (int) $day->format('j');
            $lastDay = (int) $day->format('t');
            $days = [$dayOfMonth];

            if ($skipActive && $dayOfMonth === $lastDay) {
                $days = range($dayOfMonth, 31); // JSCalendar: presume every month has 31 days
            }

            foreach ($days as $nominalDay) {
                foreach ($times as $time) {
                    $candidates[] = [$year, $month, $nominalDay, $time];
                }
            }
        }

        return $candidates;
    }

    /**
     * @param list<array{int, int, int, int}>                                                                                                                  $candidates
     * @param array{bySecond: list<int>, byMinute: list<int>, byHour: list<int>, byDay: list<array{int, int|null}>, byMonthDay: list<int>, byMonth: list<int>} $parts
     * @return list<string> sorted, unique
     */
    private static function filterCandidates(
        RecurrenceRule $rule,
        array $parts,
        array $candidates,
    ): array
    {
        $utc = new DateTimeZone('UTC');
        $byMonthDaySet = array_flip($parts['byMonthDay']);
        $result = [];

        foreach ($candidates as [$year, $month, $nominalDay, $time]) {
            if ($parts['byMonth'] !== [] && !in_array($month, $parts['byMonth'], true)) {
                continue;
            }

            $monthStart = new DateTimeImmutable("$year-$month-01", $utc);
            $daysInMonth = (int) $monthStart->format('t');
            $valid = $nominalDay <= $daysInMonth;

            if (($rule->byWeekNo !== [] || $rule->byYearDay !== []) && !$valid) {
                continue;
            }

            if ($valid) {
                $date = $monthStart->setDate($year, $month, $nominalDay);

                if ($rule->byWeekNo !== [] && !self::matchesWeekNumber($date, $rule->byWeekNo, $rule->weekStart)) {
                    continue;
                }

                if ($rule->byYearDay !== []) {
                    $dayOfYear = (int) $date->format('z') + 1;
                    $daysInYear = $date->format('L') === '1' ? 366 : 365;

                    if (!in_array($dayOfYear, $rule->byYearDay, true) && !in_array($dayOfYear - $daysInYear - 1, $rule->byYearDay, true)) {
                        continue;
                    }
                }
            }

            if ($parts['byMonthDay'] !== []) {
                if (!isset($byMonthDaySet[$nominalDay]) && !($valid && isset($byMonthDaySet[$nominalDay - $daysInMonth - 1]))) {
                    continue;
                }
            }

            $date = $valid
                ? $monthStart->setDate($year, $month, $nominalDay)
                : ($rule->skip === Skip::Forward ? self::shift($monthStart, 'first day of next month') : self::shift($monthStart, 'last day of this month'));

            if ($parts['byDay'] !== [] && !self::matchesWeekday($rule, $parts, $date)) {
                continue;
            }

            $hour = intdiv($time, 3600);
            $minute = intdiv($time % 3600, 60);
            $second = $time % 60;

            if (($parts['byHour'] !== [] && !in_array($hour, $parts['byHour'], true))
                || ($parts['byMinute'] !== [] && !in_array($minute, $parts['byMinute'], true))
                || ($parts['bySecond'] !== [] && !in_array($second, $parts['bySecond'], true))
            ) {
                continue;
            }

            $result[$date->setTime($hour, $minute, $second)->format(self::FORMAT)] = true;
        }

        // RFC 7529 / JSCalendar: a negative BYMONTHDAY before the first day of the month moves too.
        if ($rule->skip !== Skip::Omit && in_array($rule->frequency, [Frequency::Monthly, Frequency::Yearly], true) && $rule->byWeekNo === [] && $rule->byYearDay === []) {
            foreach ($candidates as [$year, $month, $nominalDay, $time]) {
                if ($nominalDay !== 1 || ($parts['byMonth'] !== [] && !in_array($month, $parts['byMonth'], true))) {
                    continue;
                }

                $monthStart = new DateTimeImmutable("$year-$month-01", $utc);
                $daysInMonth = (int) $monthStart->format('t');

                foreach ($parts['byMonthDay'] as $monthDay) {
                    if ($monthDay >= 0 || $daysInMonth + 1 + $monthDay >= 1) {
                        continue;
                    }

                    $date = $rule->skip === Skip::Forward ? $monthStart : self::shift($monthStart, '-1 day');

                    if ($parts['byDay'] !== [] && !self::matchesWeekday($rule, $parts, $date)) {
                        continue;
                    }

                    $result[$date->setTime(intdiv($time, 3600), intdiv($time % 3600, 60), $time % 60)->format(self::FORMAT)] = true;
                }
            }
        }

        $sorted = array_keys($result);
        sort($sorted);

        return $sorted;
    }

    /**
     * @param array{bySecond: list<int>, byMinute: list<int>, byHour: list<int>, byDay: list<array{int, int|null}>, byMonthDay: list<int>, byMonth: list<int>} $parts
     */
    private static function matchesWeekday(
        RecurrenceRule $rule,
        array $parts,
        DateTimeImmutable $date,
    ): bool
    {
        $weekday = (int) $date->format('N');
        $inYear = $rule->frequency === Frequency::Yearly && $parts['byMonth'] === [];

        foreach ($parts['byDay'] as [$day, $ordinal]) {
            if ($day !== $weekday) {
                continue;
            }

            if ($ordinal === null) {
                return true;
            }

            $scopeStart = $inYear ? $date->setDate((int) $date->format('Y'), 1, 1) : self::shift($date, 'first day of this month');
            $scopeEnd = $inYear ? $date->setDate((int) $date->format('Y'), 12, 31) : self::shift($date, 'last day of this month');
            $occurrences = [];

            for ($day2 = $scopeStart; $day2 <= $scopeEnd; $day2 = self::shift($day2, '+1 day')) {
                if ((int) $day2->format('N') === $weekday) {
                    $occurrences[] = $day2->format('Y-m-d');
                }
            }

            $index = array_flip($occurrences)[$date->format('Y-m-d')] ?? -1;

            if ($ordinal > 0 ? $index === $ordinal - 1 : $index === count($occurrences) + $ordinal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $weekNumbers
     */
    private static function matchesWeekNumber(
        DateTimeImmutable $date,
        array $weekNumbers,
        Weekday $weekStart,
    ): bool
    {
        $weekStartIso = $weekStart->getIsoNumber();
        $dateWeekStart = self::shift($date, '-' . (((int) $date->format('N') - $weekStartIso + 7) % 7) . ' days');
        $weekYearStart = static function (int $year) use ($date, $weekStartIso): DateTimeImmutable {
            $january4 = $date->setDate($year, 1, 4);

            return self::shift($january4, '-' . (((int) $january4->format('N') - $weekStartIso + 7) % 7) . ' days');
        };
        $year = (int) $date->format('Y');

        foreach ([$year + 1, $year, $year - 1] as $weekYear) {
            $first = $weekYearStart($weekYear);

            if ($dateWeekStart >= $first) {
                $number = intdiv(self::days($first, $dateWeekStart), 7) + 1;
                $total = intdiv(self::days($first, $weekYearStart($weekYear + 1)), 7);

                return in_array($number, $weekNumbers, true) || in_array($number - $total - 1, $weekNumbers, true);
            }
        }

        return false;
    }

    /**
     * @param list<string> $candidates
     * @param list<int>    $positions
     * @return list<string>
     */
    private static function selectSetPositions(
        array $candidates,
        array $positions,
    ): array
    {
        if ($positions === []) {
            return $candidates;
        }

        $selected = [];

        foreach ($positions as $position) {
            $index = $position > 0 ? $position - 1 : count($candidates) + $position;

            if (isset($candidates[$index])) {
                $selected[$index] = $candidates[$index];
            }
        }

        ksort($selected);

        return array_values($selected);
    }

    private static function days(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int
    {
        return $from->diff($to)->days;
    }

    private static function shift(
        DateTimeImmutable $dateTime,
        string $modifier,
    ): DateTimeImmutable
    {
        $shifted = $dateTime->modify($modifier);

        return $shifted === false ? throw new LogicException("Invalid modifier $modifier") : $shifted;
    }

}
