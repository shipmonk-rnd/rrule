<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use function array_filter;
use function array_flip;
use function array_unique;
use function intdiv;
use function sort;

/**
 * A rule compiled for one start: the parts that the rule leaves out are taken from the start
 * (the implied-parts table of the JSCalendar 2.0 expansion algorithm, which states what RFC 5545 section 3.3.10
 * means by "information not contained in the rule ... derived from DTSTART"), and every list is prepared
 * for fast matching.
 *
 * A null filter means "no restriction".
 *
 * @internal
 */
final class ExpansionPlan
{

    /**
     * @param positive-int                $interval
     * @param array<int, int>|null        $months
     * @param array<int, int>|null        $monthDays       positive and negative day-of-month values
     * @param array<int, int>|null        $yearDays        positive and negative day-of-year values
     * @param array<int, int>|null        $weekNumbers     positive and negative week numbers
     * @param array<int, int>             $plainWeekdays   weekday index (0 = Monday) => the same index
     * @param array<int, array<int, int>> $ordinalWeekdays weekday index => ordinal => the same ordinal
     * @param list<int>                   $monthDayList    the BYMONTHDAY values in rule order, for RFC 7529 SKIP
     * @param list<int>|null              $hours           sorted
     * @param list<int>|null              $minutes         sorted
     * @param list<int>|null              $seconds         sorted, without 60
     * @param list<int>                   $timesOfDay      sorted seconds of the day, for frequencies of a day or longer
     * @param list<int>                   $setPositions
     * @param int<0, 6>                   $weekStart
     */
    private function __construct(
        public readonly Frequency $frequency,
        public readonly int $interval,
        public readonly int $startLocal,
        public readonly ?array $months,
        public readonly ?array $monthDays,
        public readonly ?array $yearDays,
        public readonly ?array $weekNumbers,
        public readonly bool $hasByDay,
        public readonly array $plainWeekdays,
        public readonly array $ordinalWeekdays,
        public readonly bool $ordinalsCountInYear,
        public readonly array $monthDayList,
        public readonly int $weekStart,
        public readonly ?array $hours,
        public readonly ?array $minutes,
        public readonly ?array $seconds,
        public readonly array $timesOfDay,
        public readonly array $setPositions,
        public readonly Skip $skip,
    )
    {
    }

    public static function compile(
        RecurrenceRule $rule,
        int $startLocal,
    ): self
    {
        $frequency = $rule->frequency;
        $startDay = Civil::floorDiv($startLocal, Civil::SECONDS_PER_DAY);
        $startSecondOfDay = $startLocal - $startDay * Civil::SECONDS_PER_DAY;
        [, $startMonth, $startDayOfMonth] = Civil::civilFromDays($startDay);
        $startWeekday = Civil::weekdayIndex($startDay);

        $bySecond = $rule->bySecond;
        $byMinute = $rule->byMinute;
        $byHour = $rule->byHour;
        $byMonthDay = $rule->byMonthDay;
        $byMonth = $rule->byMonth;
        $plainWeekdays = [];
        /** @var array<int, array<int, int>> $ordinalWeekdays */
        $ordinalWeekdays = [];

        foreach ($rule->byDay as $weekdayNum) {
            $index = $weekdayNum->weekday->getIsoNumber() - 1;

            if ($weekdayNum->ordinal === null) {
                $plainWeekdays[$index] = $index;
            } else {
                $ordinalWeekdays[$index][$weekdayNum->ordinal] = $weekdayNum->ordinal;
            }
        }

        $hasByDay = $rule->byDay !== [];

        if ($bySecond === [] && $frequency !== Frequency::Secondly) {
            $bySecond = [$startSecondOfDay % 60];
        }

        if ($byMinute === [] && $frequency !== Frequency::Secondly && $frequency !== Frequency::Minutely) {
            $byMinute = [intdiv($startSecondOfDay % 3600, 60)];
        }

        if ($byHour === [] && self::isAtLeastDaily($frequency)) {
            $byHour = [intdiv($startSecondOfDay, 3600)];
        }

        if ($frequency === Frequency::Weekly && !$hasByDay) {
            $plainWeekdays[$startWeekday] = $startWeekday;
            $hasByDay = true;
        }

        if ($frequency === Frequency::Monthly && !$hasByDay && $byMonthDay === []) {
            $byMonthDay = [$startDayOfMonth];
        }

        if ($frequency === Frequency::Yearly && $rule->byYearDay === []) {
            if ($byMonth === [] && $rule->byWeekNo === [] && ($byMonthDay !== [] || !$hasByDay)) {
                $byMonth = [$startMonth];
            }

            if ($byMonthDay === [] && $rule->byWeekNo === [] && !$hasByDay) {
                $byMonthDay = [$startDayOfMonth];
            }

            if ($rule->byWeekNo !== [] && $byMonthDay === [] && !$hasByDay) {
                $plainWeekdays[$startWeekday] = $startWeekday;
                $hasByDay = true;
            }
        }

        $hours = $byHour === [] ? null : self::sortedUnique($byHour);
        $minutes = $byMinute === [] ? null : self::sortedUnique($byMinute);
        $seconds = $bySecond === [] ? null : self::sortedUnique(array_filter($bySecond, static fn (int $second): bool => $second !== 60));

        $timesOfDay = [];

        if (self::isAtLeastDaily($frequency)) {
            foreach ($hours ?? [] as $hour) {
                foreach ($minutes ?? [] as $minute) {
                    foreach ($seconds ?? [] as $second) {
                        $timesOfDay[] = $hour * 3600 + $minute * 60 + $second;
                    }
                }
            }
        }

        return new self(
            frequency: $frequency,
            interval: $rule->interval,
            startLocal: $startLocal,
            months: self::toSet($byMonth),
            monthDays: self::toSet($byMonthDay),
            yearDays: self::toSet($rule->byYearDay),
            weekNumbers: self::toSet($rule->byWeekNo),
            hasByDay: $hasByDay,
            plainWeekdays: $plainWeekdays,
            ordinalWeekdays: $ordinalWeekdays,
            ordinalsCountInYear: $frequency === Frequency::Yearly && $rule->byMonth === [], // RFC 5545 erratum 3779: BYMONTH as written in the rule
            monthDayList: $byMonthDay,
            weekStart: $rule->weekStart->getIsoNumber() - 1,
            hours: $hours,
            minutes: $minutes,
            seconds: $seconds,
            timesOfDay: $timesOfDay,
            setPositions: $rule->bySetPos,
            skip: $rule->skip,
        );
    }

    public static function isAtLeastDaily(Frequency $frequency): bool
    {
        return $frequency === Frequency::Daily
            || $frequency === Frequency::Weekly
            || $frequency === Frequency::Monthly
            || $frequency === Frequency::Yearly;
    }

    /**
     * RFC 7529 SKIP changes invalid dates instead of dropping them. Only BYMONTHDAY can produce an invalid
     * Gregorian date, and only monthly and yearly periods contain more than real days.
     */
    public function usesSkip(): bool
    {
        return $this->skip !== Skip::Omit
            && $this->monthDays !== null
            && ($this->frequency === Frequency::Monthly || $this->frequency === Frequency::Yearly);
    }

    /**
     * @param list<int> $values
     * @return array<int, int>|null value => index
     */
    private static function toSet(array $values): ?array
    {
        return $values === [] ? null : array_flip($values);
    }

    /**
     * @param array<int> $values
     * @return list<int>
     */
    private static function sortedUnique(array $values): array
    {
        $values = array_unique($values);
        sort($values);

        return $values;
    }

}
