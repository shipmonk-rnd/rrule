<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use LogicException;
use function array_key_first;
use function count;
use function intdiv;

/**
 * Finds the calendar dates that pass the date filters of a plan (BYMONTH, BYWEEKNO, BYYEARDAY, BYMONTHDAY, BYDAY).
 *
 * Whether a date passes does not depend on the period that contains it: BYDAY ordinals count within the month
 * or the year, and every date has exactly one week number (JSCalendar 2.0 clarification of RFC 5545 BYWEEKNO).
 * So the matching dates are computed once per calendar year.
 *
 * @internal
 */
final class DateMatcher
{

    private const CACHED_YEARS = 8;

    /**
     * @var array<int, array{list<int>, array<int, true>}> year => [sorted matching day numbers, day number => true]
     */
    private array $years = [];

    public function __construct(
        private readonly ExpansionPlan $plan,
    )
    {
    }

    public function matches(int $day): bool
    {
        if (!$this->plan->hasDateFilter()) {
            return true;
        }

        return isset($this->getYear(Civil::civilFromDays($day)[0])[1][$day]);
    }

    /**
     * Applies only the BYDAY filter (used for dates that RFC 7529 SKIP moved).
     */
    public function matchesByDay(int $day): bool
    {
        if (!$this->plan->hasByDay) {
            return true;
        }

        [$year, $month, $dayOfMonth] = Civil::civilFromDays($day);

        return $this->matchesWeekday(
            $day,
            $dayOfMonth,
            Civil::daysInMonth($year, $month),
            $day - Civil::daysFromCivil($year, 1, 1) + 1,
            Civil::daysInYear($year),
        );
    }

    /**
     * @return list<int> sorted day numbers
     */
    public function getDaysOfYear(int $year): array
    {
        return $this->getYear($year)[0];
    }

    /**
     * The first matching date on or after the given day. The Gregorian calendar repeats after 400 years,
     * so a search through 401 years without a match proves that no date ever matches.
     */
    public function findNextMatchingDay(int $fromDay): ?int
    {
        $fromYear = Civil::civilFromDays($fromDay)[0];

        for ($year = $fromYear; $year <= $fromYear + 401 && $year <= Civil::MAX_YEAR; $year++) {
            foreach ($this->getYear($year)[0] as $day) {
                if ($day >= $fromDay) {
                    return $day;
                }
            }
        }

        return null;
    }

    /**
     * @return array{list<int>, array<int, true>}
     */
    private function getYear(int $year): array
    {
        if (!isset($this->years[$year])) {
            if (count($this->years) >= self::CACHED_YEARS) {
                unset($this->years[array_key_first($this->years)]);
            }

            $this->years[$year] = $this->computeYear($year);
        }

        return $this->years[$year];
    }

    /**
     * @return array{list<int>, array<int, true>}
     */
    private function computeYear(int $year): array
    {
        $plan = $this->plan;
        $yearStart = Civil::daysFromCivil($year, 1, 1);
        $daysInYear = Civil::daysInYear($year);
        $weekYearStarts = $plan->weekNumbers === null ? [] : $this->getWeekYearStarts($year);
        $days = [];
        $daySet = [];

        for ($month = 1; $month <= 12; $month++) {
            if ($plan->months !== null && !isset($plan->months[$month])) {
                continue;
            }

            $daysInMonth = Civil::daysInMonth($year, $month);
            $monthStart = Civil::daysFromCivil($year, $month, 1);

            for ($dayOfMonth = 1; $dayOfMonth <= $daysInMonth; $dayOfMonth++) {
                $day = $monthStart + $dayOfMonth - 1;

                if (
                    $plan->monthDays !== null
                    && !isset($plan->monthDays[$dayOfMonth])
                    && !isset($plan->monthDays[$dayOfMonth - $daysInMonth - 1])
                ) {
                    continue;
                }

                $dayOfYear = $day - $yearStart + 1;

                if (
                    $plan->yearDays !== null
                    && !isset($plan->yearDays[$dayOfYear])
                    && !isset($plan->yearDays[$dayOfYear - $daysInYear - 1])
                ) {
                    continue;
                }

                if ($plan->weekNumbers !== null && !$this->matchesWeekNumber($day, $weekYearStarts)) {
                    continue;
                }

                if ($plan->hasByDay && !$this->matchesWeekday($day, $dayOfMonth, $daysInMonth, $dayOfYear, $daysInYear)) {
                    continue;
                }

                $days[] = $day;
                $daySet[$day] = true;
            }
        }

        return [$days, $daySet];
    }

    /**
     * @param array<int, int> $weekYearStarts week-numbering year => day number of the first day of its week 1
     */
    private function matchesWeekNumber(
        int $day,
        array $weekYearStarts,
    ): bool
    {
        $weekStart = $this->getWeekStart($day);
        $weekYear = null;

        foreach ($weekYearStarts as $candidateYear => $firstWeekStart) {
            if ($weekStart >= $firstWeekStart) {
                $weekYear = $candidateYear;
            }
        }

        $firstWeekStart = $weekYearStarts[$weekYear ?? 0] ?? throw new LogicException('Week-numbering year not found');
        $nextFirstWeekStart = $weekYearStarts[($weekYear ?? 0) + 1] ?? throw new LogicException('Week-numbering year not found');
        $weekNumber = intdiv($weekStart - $firstWeekStart, 7) + 1;
        $weeksInYear = intdiv($nextFirstWeekStart - $firstWeekStart, 7);

        return isset($this->plan->weekNumbers[$weekNumber])
            || isset($this->plan->weekNumbers[$weekNumber - $weeksInYear - 1]);
    }

    private function matchesWeekday(
        int $day,
        int $dayOfMonth,
        int $daysInMonth,
        int $dayOfYear,
        int $daysInYear,
    ): bool
    {
        $weekday = Civil::weekdayIndex($day);

        if (isset($this->plan->plainWeekdays[$weekday])) {
            return true;
        }

        if (!isset($this->plan->ordinalWeekdays[$weekday])) {
            return false;
        }

        [$position, $length] = $this->plan->ordinalsCountInYear ? [$dayOfYear, $daysInYear] : [$dayOfMonth, $daysInMonth];
        $ordinals = $this->plan->ordinalWeekdays[$weekday];

        return isset($ordinals[intdiv($position - 1, 7) + 1])
            || isset($ordinals[-(intdiv($length - $position, 7) + 1)]);
    }

    /**
     * Week 1 of a week-numbering year is the first week (starting on WKST) with at least four days in that year,
     * which is the week that contains January 4.
     *
     * @return array<int, int>
     */
    private function getWeekYearStarts(int $year): array
    {
        $starts = [];

        for ($weekYear = $year - 1; $weekYear <= $year + 2; $weekYear++) {
            $starts[$weekYear] = $this->getWeekStart(Civil::daysFromCivil($weekYear, 1, 4));
        }

        return $starts;
    }

    private function getWeekStart(int $day): int
    {
        return $day - Civil::floorMod(Civil::weekdayIndex($day) - $this->plan->weekStart, 7);
    }

}
