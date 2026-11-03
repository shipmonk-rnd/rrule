<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use Generator;
use LogicException;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Skip;
use function abs;
use function array_filter;
use function array_flip;
use function count;
use function intdiv;
use function max;
use function min;
use function range;
use function sort;
use const PHP_INT_MAX;

/**
 * Expands one compiled rule in local wall-clock seconds, period by period (RFC 5545 section 3.3.10, in the
 * order of the JSCalendar 2.0 expansion algorithm):
 *
 * 1. the period is the year, month, week (from WKST), day, hour, minute or second that INTERVAL selects;
 * 2. its candidates are the dates that pass the date filters, combined with the times of day;
 * 3. BYSETPOS selects from the sorted candidates of the whole period;
 * 4. candidates before the start are dropped.
 *
 * COUNT, UNTIL, time zones and duplicates are handled by {@see RuleStream}.
 *
 * Termination: the Gregorian calendar repeats after 400 years (146097 days, a multiple of 7), so with INTERVAL n
 * the sequence of periods and their contents repeats after a known cycle. When a whole cycle passes without a
 * candidate, no candidate can follow, and the expansion stops. Empty periods are skipped in one jump to the next
 * date or time that can match.
 *
 * @internal
 */
final class LocalRuleIterator
{

    private readonly int $maxLocal;

    private readonly bool $daily;

    /**
     * @var array<int, int>|null
     */
    private readonly ?array $hourSet;

    /**
     * @var array<int, int>|null
     */
    private readonly ?array $minuteSet;

    /**
     * @var array<int, int>|null
     */
    private readonly ?array $secondSet;

    /**
     * @var array<int, int>|null
     */
    private readonly ?array $setPositionSet;

    public function __construct(
        private readonly ExpansionPlan $plan,
        private readonly DateMatcher $matcher,
        private readonly SearchBudget $budget,
    )
    {
        $this->maxLocal = Civil::localSeconds(Civil::daysFromCivil(Civil::MAX_YEAR, 12, 31), 23, 59, 59);
        $this->daily = ExpansionPlan::isAtLeastDaily($plan->frequency);
        $this->hourSet = $plan->hours === null ? null : array_flip($plan->hours);
        $this->minuteSet = $plan->minutes === null ? null : array_flip($plan->minutes);
        $this->secondSet = $plan->seconds === null ? null : array_flip($plan->seconds);
        $this->setPositionSet = $plan->setPositions === [] ? null : array_flip($plan->setPositions);
    }

    /**
     * Candidates in ascending order, all within [max(start, from), min(to, 9999-12-31T23:59:59)].
     *
     * @return Generator<int, int>
     */
    public function generate(
        ?int $fromLocal,
        ?int $toLocal,
    ): Generator
    {
        $lower = max($this->plan->startLocal, $fromLocal ?? $this->plan->startLocal);
        $upper = min($this->maxLocal, $toLocal ?? $this->maxLocal);

        if ($lower > $upper || !$this->canEverMatch()) {
            return;
        }

        if ($this->daily) {
            yield from $this->generateDaily($lower, $upper);
        } else {
            yield from $this->generateSubDaily($lower, $upper);
        }
    }

    /**
     * @return Generator<int, int>
     */
    private function generateDaily(
        int $lower,
        int $upper,
    ): Generator
    {
        $plan = $this->plan;
        $interval = $plan->interval;
        $startDay = Civil::floorDiv($plan->startLocal, Civil::SECONDS_PER_DAY);
        $startUnit = $this->getDailyUnit($startDay);
        $maxDay = Civil::floorDiv($this->maxLocal, Civil::SECONDS_PER_DAY);
        $maxPeriod = intdiv($this->getDailyUnit($maxDay) - $startUnit, $interval);
        $upperDay = Civil::floorDiv($upper, Civil::SECONDS_PER_DAY);
        $cycleDays = $interval > intdiv(PHP_INT_MAX, Civil::DAYS_PER_400_YEARS) ? null : $interval * Civil::DAYS_PER_400_YEARS;
        $period = Civil::floorDiv($this->getDailyUnit(Civil::floorDiv($lower, Civil::SECONDS_PER_DAY)) - $startUnit, $interval);
        $quietSince = null;

        while ($period <= $maxPeriod) {
            $this->budget->step();
            [$firstDay, $lastDay, $days] = $this->getPeriodDays($startUnit + $period * $interval);

            if ($firstDay > $upperDay) {
                return;
            }

            $candidates = [];

            foreach ($days as $day) {
                foreach ($plan->timesOfDay as $timeOfDay) {
                    $candidates[] = $day * Civil::SECONDS_PER_DAY + $timeOfDay;
                }
            }

            $candidates = $this->selectSetPositions($candidates);

            if ($candidates === []) {
                $quietSince ??= $firstDay;

                if ($cycleDays !== null && $firstDay - $quietSince >= $cycleDays) {
                    return;
                }

                if ($plan->usesSkip()) {
                    $period++;
                    continue;
                }

                $nextDay = $this->matcher->findNextMatchingDay($lastDay + 1);

                if ($nextDay === null) {
                    return;
                }

                // the first period that contains the next matching day; it is after this period
                $period = -Civil::floorDiv($startUnit - $this->getDailyUnit($nextDay), $interval);
                continue;
            }

            $quietSince = null;

            foreach ($candidates as $candidate) {
                if ($candidate > $upper) {
                    return;
                }

                if ($candidate >= $lower) {
                    yield $candidate;
                }
            }

            $period++;
        }
    }

    /**
     * @return Generator<int, int>
     */
    private function generateSubDaily(
        int $lower,
        int $upper,
    ): Generator
    {
        $plan = $this->plan;
        $unit = match ($plan->frequency) {
            Frequency::Hourly => 3600,
            Frequency::Minutely => 60,
            Frequency::Secondly => 1,
            Frequency::Daily, Frequency::Weekly, Frequency::Monthly, Frequency::Yearly => throw new LogicException('Not a sub-daily frequency'),
        };
        $interval = $plan->interval;
        $firstPeriodStart = $plan->startLocal - Civil::floorMod($plan->startLocal, $unit);
        $maxPeriod = intdiv(intdiv($this->maxLocal - $firstPeriodStart, $unit), $interval);
        $cycleSeconds = $interval > intdiv(PHP_INT_MAX, $unit)
            ? null
            : self::lcm($interval * $unit, Civil::DAYS_PER_400_YEARS * Civil::SECONDS_PER_DAY);
        $period = Civil::floorDiv(Civil::floorDiv($lower - $firstPeriodStart, $unit), $interval);
        $quietSince = null;

        while ($period <= $maxPeriod) {
            $this->budget->step();
            $periodStart = $firstPeriodStart + $period * $interval * $unit;

            if ($periodStart > $upper) {
                return;
            }

            $nextCandidateTime = $this->findNextSubDailyTime($periodStart);
            $candidates = $nextCandidateTime === $periodStart ? $this->getSubDailyCandidates($periodStart) : [];

            if ($candidates === []) {
                $quietSince ??= $periodStart;

                if (($cycleSeconds !== null && $periodStart - $quietSince >= $cycleSeconds) || $nextCandidateTime === null) {
                    return;
                }

                // the next candidate time is aligned to the unit and later than this period start
                $period = -Civil::floorDiv(-intdiv($nextCandidateTime - $firstPeriodStart, $unit), $interval);
                continue;
            }

            $quietSince = null;

            foreach ($candidates as $candidate) {
                if ($candidate > $upper) {
                    return;
                }

                if ($candidate >= $lower) {
                    yield $candidate;
                }
            }

            $period++;
        }
    }

    /**
     * Detects rules whose periods can never meet their filters, without iterating: no time of day (BYSECOND=60 only),
     * sub-daily periods whose start times never fall on an allowed hour, minute or second (for example
     * FREQ=HOURLY;INTERVAL=2;BYHOUR=1 from midnight), and DAILY with an INTERVAL that is a multiple of 7 on a weekday
     * that BYDAY excludes. Other impossible rules end through the 400-year cycle check.
     */
    private function canEverMatch(): bool
    {
        $plan = $this->plan;

        if (!$this->canSetPositionsEverSelect()) {
            return false;
        }

        if ($this->daily) {
            if ($plan->timesOfDay === []) {
                return false;
            }

            if ($plan->frequency === Frequency::Daily && $plan->hasByDay && $plan->interval % 7 === 0) {
                $weekday = Civil::weekdayIndex(Civil::floorDiv($plan->startLocal, Civil::SECONDS_PER_DAY));

                return isset($plan->plainWeekdays[$weekday]);
            }

            return true;
        }

        if ($plan->seconds === []) {
            return false;
        }

        [$unit, $hours, $minutes, $seconds] = match ($plan->frequency) {
            Frequency::Hourly => [3600, $plan->hours, [0], [0]],
            Frequency::Minutely => [60, $plan->hours, $plan->minutes, [0]],
            Frequency::Secondly => [1, $plan->hours, $plan->minutes, $plan->seconds],
            Frequency::Daily, Frequency::Weekly, Frequency::Monthly, Frequency::Yearly => throw new LogicException('Not a sub-daily frequency'),
        };

        if ($plan->interval > intdiv(PHP_INT_MAX, $unit)) {
            return true;
        }

        // Period start times are the start plus multiples of INTERVAL units, so modulo one day they are exactly
        // the times congruent to the start modulo gcd(INTERVAL units, 1 day).
        $step = self::gcd($plan->interval * $unit, Civil::SECONDS_PER_DAY);
        $startPhase = Civil::floorMod($plan->startLocal - Civil::floorMod($plan->startLocal, $unit), $step);

        foreach ($hours ?? range(0, 23) as $hour) {
            foreach ($minutes ?? range(0, 59) as $minute) {
                foreach ($seconds ?? range(0, 59) as $second) {
                    if (Civil::floorMod($hour * 3600 + $minute * 60 + $second, $step) === $startPhase) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * BYSETPOS selects nothing when every position is beyond the largest possible number of candidates
     * in one period (for example BYSETPOS=2 with FREQ=MINUTELY and a single second).
     */
    private function canSetPositionsEverSelect(): bool
    {
        $plan = $this->plan;

        if ($plan->setPositions === []) {
            return true;
        }

        $maxCandidates = match ($plan->frequency) {
            Frequency::Yearly => 366 * count($plan->timesOfDay),
            Frequency::Monthly => 31 * count($plan->timesOfDay),
            Frequency::Weekly => 7 * count($plan->timesOfDay),
            Frequency::Daily => count($plan->timesOfDay),
            Frequency::Hourly => count($plan->minutes ?? []) * count($plan->seconds ?? []),
            Frequency::Minutely => count($plan->seconds ?? []),
            Frequency::Secondly => 1,
        };

        foreach ($plan->setPositions as $position) {
            if (abs($position) <= $maxCandidates) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index of the year, month, week or day that contains the day, counted the way INTERVAL counts.
     */
    private function getDailyUnit(int $day): int
    {
        return match ($this->plan->frequency) {
            Frequency::Yearly => Civil::civilFromDays($day)[0],
            Frequency::Monthly => self::getMonthIndex($day),
            Frequency::Weekly => Civil::floorDiv($day - Civil::floorMod(Civil::weekdayIndex($day) - $this->plan->weekStart, 7), 7),
            Frequency::Daily => $day,
            Frequency::Hourly, Frequency::Minutely, Frequency::Secondly => throw new LogicException('Not a daily or longer frequency'),
        };
    }

    /**
     * @return array{int, int, array<int, int>} first day, last day and the matching days of the period (ascending)
     */
    private function getPeriodDays(int $unit): array
    {
        switch ($this->plan->frequency) {
            case Frequency::Yearly:
                $firstDay = Civil::firstDayOfYear($unit);
                $lastDay = Civil::firstDayOfYear($unit + 1) - 1;

                return [$firstDay, $lastDay, $this->plan->usesSkip() ? $this->getSkipDays($unit, null) : $this->matcher->getDaysOfYear($unit)];

            case Frequency::Monthly:
                $year = Civil::floorDiv($unit, 12);
                $month = $unit - $year * 12 + 1;
                $firstDay = Civil::daysFromCivil($year, $month, 1);
                $lastDay = $firstDay + Civil::daysInMonth($year, $month) - 1;

                if ($this->plan->usesSkip()) {
                    return [$firstDay, $lastDay, $this->getSkipDays($year, $month)];
                }

                $days = array_filter($this->matcher->getDaysOfYear($year), static fn (int $day): bool => $day >= $firstDay && $day <= $lastDay);

                return [$firstDay, $lastDay, $days];

            case Frequency::Weekly:
                $firstDay = $unit * 7 + Civil::floorMod($this->plan->weekStart - 3, 7); // day 0 (1970-01-01) is a Thursday
                $days = [];

                for ($day = $firstDay; $day < $firstDay + 7; $day++) {
                    if ($this->matcher->matches($day)) {
                        $days[] = $day;
                    }
                }

                return [$firstDay, $firstDay + 6, $days];

            default:
                return [$unit, $unit, $this->matcher->matches($unit) ? [$unit] : []];
        }
    }

    /**
     * BYMONTHDAY candidates with RFC 7529 SKIP: an invalid day of month moves to the last valid day before it
     * (BACKWARD) or to the first day after it (FORWARD). A moved date is still filtered by BYDAY, but not by
     * BYWEEKNO or BYYEARDAY, which eliminate invalid dates (JSCalendar 2.0 processing order).
     *
     * @return list<int>
     */
    private function getSkipDays(
        int $year,
        ?int $onlyMonth,
    ): array
    {
        $plan = $this->plan;
        $daySet = [];

        for ($month = $onlyMonth ?? 1; $month <= ($onlyMonth ?? 12); $month++) {
            if ($plan->months !== null && !isset($plan->months[$month])) {
                continue;
            }

            $daysInMonth = Civil::daysInMonth($year, $month);
            $monthStart = Civil::daysFromCivil($year, $month, 1);

            foreach ($plan->monthDayList as $monthDay) {
                $dayOfMonth = $monthDay > 0 ? $monthDay : $daysInMonth + 1 + $monthDay;

                if ($dayOfMonth >= 1 && $dayOfMonth <= $daysInMonth) {
                    $day = $monthStart + $dayOfMonth - 1;

                    if ($this->matcher->matches($day)) {
                        $daySet[$day] = $day;
                    }

                    continue;
                }

                if ($plan->yearDays !== null || $plan->weekNumbers !== null) {
                    continue;
                }

                $forward = $plan->skip === Skip::Forward;
                $day = $dayOfMonth > $daysInMonth
                    ? ($forward ? $monthStart + $daysInMonth : $monthStart + $daysInMonth - 1)
                    : ($forward ? $monthStart : $monthStart - 1);

                if ($this->matcher->matchesByDay($day)) {
                    $daySet[$day] = $day;
                }
            }
        }

        sort($daySet);

        return $daySet;
    }

    /**
     * The first period start time at or after the given one that passes the date filters and BYHOUR, BYMINUTE
     * and BYSECOND, as far as the frequency unit sees them; null when no date ever matches.
     */
    private function findNextSubDailyTime(int $time): ?int
    {
        $frequency = $this->plan->frequency;
        $day = Civil::floorDiv($time, Civil::SECONDS_PER_DAY);
        $dayStart = $day * Civil::SECONDS_PER_DAY;
        $secondOfDay = $time - $dayStart;
        $hour = intdiv($secondOfDay, 3600);
        $minute = intdiv($secondOfDay % 3600, 60);
        $second = $secondOfDay % 60;

        if (!$this->matcher->matches($day)) {
            $nextDay = $this->matcher->findNextMatchingDay($day);

            return $nextDay === null ? null : $nextDay * Civil::SECONDS_PER_DAY;
        }

        if ($this->hourSet !== null && !isset($this->hourSet[$hour])) {
            $nextHour = self::findNextInList($this->plan->hours ?? [], $hour);

            return $nextHour === null ? $dayStart + Civil::SECONDS_PER_DAY : $dayStart + $nextHour * 3600;
        }

        if ($frequency !== Frequency::Hourly && $this->minuteSet !== null && !isset($this->minuteSet[$minute])) {
            $nextMinute = self::findNextInList($this->plan->minutes ?? [], $minute);

            return $nextMinute === null ? $dayStart + ($hour + 1) * 3600 : $dayStart + $hour * 3600 + $nextMinute * 60;
        }

        if ($frequency === Frequency::Secondly && $this->secondSet !== null && !isset($this->secondSet[$second])) {
            $nextSecond = self::findNextInList($this->plan->seconds ?? [], $second);

            return $nextSecond === null ? $time - $second + 60 : $time - $second + $nextSecond;
        }

        return $time;
    }

    /**
     * @return list<int>
     */
    private function getSubDailyCandidates(int $periodStart): array
    {
        $plan = $this->plan;
        $candidates = [];

        if ($plan->frequency === Frequency::Hourly) {
            foreach ($plan->minutes ?? [] as $minute) {
                foreach ($plan->seconds ?? [] as $second) {
                    $candidates[] = $periodStart + $minute * 60 + $second;
                }
            }
        } elseif ($plan->frequency === Frequency::Minutely) {
            foreach ($plan->seconds ?? [] as $second) {
                $candidates[] = $periodStart + $second;
            }
        } else {
            $candidates[] = $periodStart;
        }

        return $this->selectSetPositions($candidates);
    }

    /**
     * @param list<int> $candidates sorted
     * @return list<int>
     */
    private function selectSetPositions(array $candidates): array
    {
        if ($this->setPositionSet === null) {
            return $candidates;
        }

        $count = count($candidates);
        $selected = [];

        foreach ($candidates as $index => $candidate) {
            if (isset($this->setPositionSet[$index + 1]) || isset($this->setPositionSet[$index - $count])) {
                $selected[] = $candidate;
            }
        }

        return $selected;
    }

    /**
     * The least common multiple, or null when it does not fit in an integer.
     */
    private static function lcm(
        int $a,
        int $b,
    ): ?int
    {
        $reduced = intdiv($a, self::gcd($a, $b));

        return $reduced > intdiv(PHP_INT_MAX, $b) ? null : $reduced * $b;
    }

    private static function gcd(
        int $a,
        int $b,
    ): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    private static function getMonthIndex(int $day): int
    {
        [$year, $month] = Civil::civilFromDays($day);

        return $year * 12 + $month - 1;
    }

    /**
     * @param list<int> $sorted
     */
    private static function findNextInList(
        array $sorted,
        int $after,
    ): ?int
    {
        foreach ($sorted as $value) {
            if ($value > $after) {
                return $value;
            }
        }

        return null;
    }

}
