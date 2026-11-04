<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use function array_key_first;
use function count;
use function intdiv;
use function min;

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
     * @var array<int, array<int, int>> year => matching day numbers in ascending order, as day number => day number
     */
    private array $years = [];

    public function __construct(
        private readonly ExpansionPlan $plan,
    )
    {
    }

    public function matches(int $day): bool
    {
        return isset($this->getYear(Civil::civilFromDays($day)[0])[$day]);
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

        // SKIP needs BYMONTHDAY, which implies BYMONTH for YEARLY, so an ordinal counts within the month here
        return $this->matchesWeekday($day, $dayOfMonth, Civil::daysInMonth($year, $month));
    }

    /**
     * @return array<int, int> day numbers in ascending order, as day number => day number
     */
    public function getDaysOfYear(int $year): array
    {
        return $this->getYear($year);
    }

    /**
     * The first matching date on or after the given day. The Gregorian calendar repeats after 400 years (146097
     * days), so every 146097 consecutive days contain a match, or no date ever matches.
     */
    public function findNextMatchingDay(int $fromDay): ?int
    {
        $fromYear = Civil::civilFromDays($fromDay)[0];
        $lastYear = min($fromYear + 400, Civil::MAX_YEAR);

        for ($year = $fromYear; $year <= $lastYear; $year++) {
            foreach ($this->getYear($year) as $day) {
                if ($day >= $fromDay) {
                    return $day;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
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
     * @return array<int, int>
     */
    private function computeYear(int $year): array
    {
        $plan = $this->plan;
        $yearStart = Civil::firstDayOfYear($year);
        $daysInYear = Civil::daysInYear($year);
        $days = [];

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

                if ($plan->weekNumbers !== null && !$this->matchesWeekNumber($day)) {
                    continue;
                }

                $inYear = $plan->ordinalsCountInYear;

                if ($plan->hasByDay && !$this->matchesWeekday($day, $inYear ? $dayOfYear : $dayOfMonth, $inYear ? $daysInYear : $daysInMonth)) {
                    continue;
                }

                $days[$day] = $day;
            }
        }

        return $days;
    }

    /**
     * Week 1 of a week-numbering year is the first week (starting on WKST) with at least four days in that year,
     * which is the week that contains January 4. So a week belongs to the year of its fourth day.
     */
    private function matchesWeekNumber(int $day): bool
    {
        $weekStart = $this->getWeekStart($day);
        $weekYear = Civil::civilFromDays($weekStart + 3)[0];
        $firstWeekStart = $this->getWeekStart(Civil::daysFromCivil($weekYear, 1, 4));
        $weekNumber = intdiv($weekStart - $firstWeekStart, 7) + 1;
        $weeksInYear = intdiv($this->getWeekStart(Civil::daysFromCivil($weekYear + 1, 1, 4)) - $firstWeekStart, 7);

        return isset($this->plan->weekNumbers[$weekNumber])
            || isset($this->plan->weekNumbers[$weekNumber - $weeksInYear - 1]);
    }

    /**
     * @param int $position the position of the day in the period that ordinals count in (the month or the year)
     * @param int $length   the length of that period in days
     */
    private function matchesWeekday(
        int $day,
        int $position,
        int $length,
    ): bool
    {
        $weekday = Civil::weekdayIndex($day);

        if (isset($this->plan->plainWeekdays[$weekday])) {
            return true;
        }

        if (!isset($this->plan->ordinalWeekdays[$weekday])) {
            return false;
        }

        $ordinals = $this->plan->ordinalWeekdays[$weekday];

        return isset($ordinals[intdiv($position - 1, 7) + 1])
            || isset($ordinals[-(intdiv($length - $position, 7) + 1)]);
    }

    private function getWeekStart(int $day): int
    {
        return $day - Civil::floorMod(Civil::weekdayIndex($day) - $this->plan->weekStart, 7);
    }

}
