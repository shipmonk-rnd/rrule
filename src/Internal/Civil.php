<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use function intdiv;

/**
 * Proleptic Gregorian calendar arithmetic on integers.
 *
 * Day numbers count days since 1970-01-01. Local seconds count seconds since 1970-01-01T00:00:00 of a wall clock
 * that has no time zone, so every day has exactly 86400 seconds.
 *
 * The day-number algorithms are from Howard Hinnant, "chrono-Compatible Low-Level Date Algorithms".
 *
 * @internal
 */
final class Civil
{

    public const SECONDS_PER_DAY = 86_400;
    public const MIN_YEAR = 1;
    public const MAX_YEAR = 9_999;

    /**
     * The Gregorian calendar repeats after 400 years. That cycle has 146097 days, a multiple of 7.
     */
    public const DAYS_PER_400_YEARS = 146_097;

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    /**
     * @return int<28, 31>
     */
    public static function daysInMonth(
        int $year,
        int $month,
    ): int
    {
        return match ($month) {
            2 => self::isLeapYear($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /**
     * @return int<365, 366>
     */
    public static function daysInYear(int $year): int
    {
        return self::isLeapYear($year) ? 366 : 365;
    }

    public static function isValidDate(
        int $year,
        int $month,
        int $day,
    ): bool
    {
        return $year >= self::MIN_YEAR
            && $year <= self::MAX_YEAR
            && $month >= 1
            && $month <= 12
            && $day >= 1
            && $day <= self::daysInMonth($year, $month);
    }

    public static function daysFromCivil(
        int $year,
        int $month,
        int $day,
    ): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month > 2 ? $month - 3 : $month + 9) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * self::DAYS_PER_400_YEARS + $dayOfEra - 719_468;
    }

    /**
     * @return array{int, int<1, 12>, int<1, 31>}
     */
    public static function civilFromDays(int $days): array
    {
        $days += 719_468;
        $era = intdiv($days >= 0 ? $days : $days - 146_096, self::DAYS_PER_400_YEARS);
        $dayOfEra = $days - $era * self::DAYS_PER_400_YEARS;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36_524) - intdiv($dayOfEra, 146_096), 365);
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $monthPart = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $monthPart + 2, 5) + 1;
        $month = $monthPart < 10 ? $monthPart + 3 : $monthPart - 9;

        /** @var int<1, 12> $month */
        /** @var int<1, 31> $day */
        return [$yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }

    /**
     * @return int<0, 6> 0 is Monday, 6 is Sunday
     */
    public static function weekdayIndex(int $days): int
    {
        // 1970-01-01 was a Thursday (index 3)
        return (($days % 7) + 10) % 7;
    }

    public static function localSeconds(
        int $days,
        int $hour,
        int $minute,
        int $second,
    ): int
    {
        return $days * self::SECONDS_PER_DAY + $hour * 3600 + $minute * 60 + $second;
    }

    /**
     * Division rounded towards negative infinity. The divisor must be positive.
     */
    public static function floorDiv(
        int $dividend,
        int $divisor,
    ): int
    {
        $quotient = intdiv($dividend, $divisor);

        return $dividend % $divisor < 0 ? $quotient - 1 : $quotient;
    }

    /**
     * The non-negative remainder of {@see self::floorDiv()}. The divisor must be positive.
     */
    public static function floorMod(
        int $dividend,
        int $divisor,
    ): int
    {
        $remainder = $dividend % $divisor;

        return $remainder < 0 ? $remainder + $divisor : $remainder;
    }

}
