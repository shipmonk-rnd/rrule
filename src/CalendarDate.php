<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Internal\Civil;
use ShipMonk\RRule\Internal\TimeZoneResolver;
use function sprintf;

/**
 * A calendar date without a time: the RFC 5545 DATE value type (section 3.3.4), used for all-day recurrences.
 *
 * @api
 */
final class CalendarDate
{

    /**
     * @param int<1, 9999> $year
     * @param int<1, 12>   $month
     * @param int<1, 31>   $day
     */
    private function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
    )
    {
    }

    /**
     * @throws InvalidValueException
     */
    public static function of(
        int $year,
        int $month,
        int $day,
    ): self
    {
        if (!Civil::isValidDate($year, $month, $day)) {
            throw new InvalidValueException(sprintf('Invalid date %04d-%02d-%02d (years 1 to 9999 are supported)', $year, $month, $day));
        }

        /** @var int<1, 9999> $year */
        /** @var int<1, 12> $month */
        /** @var int<1, 31> $day */
        return new self($year, $month, $day);
    }

    /**
     * Takes the wall-clock date of the value, in its own time zone.
     *
     * @throws InvalidValueException
     */
    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return self::of((int) $dateTime->format('Y'), (int) $dateTime->format('n'), (int) $dateTime->format('j'));
    }

    /**
     * The date that is the given number of days after 1970-01-01 (negative: before it).
     *
     * @throws InvalidArgumentException when the date is outside the years 1 to 9999; it is unchecked
     */
    public static function fromDayNumber(int $days): self
    {
        [$year, $month, $day] = Civil::civilFromDays($days);

        if ($year < Civil::MIN_YEAR || $year > Civil::MAX_YEAR) {
            throw new InvalidArgumentException(sprintf('Day number %d is outside the years %d to %d', $days, Civil::MIN_YEAR, Civil::MAX_YEAR));
        }

        return new self($year, $month, $day);
    }

    /**
     * The number of days from 1970-01-01 to this date (negative: before it).
     */
    public function toDayNumber(): int
    {
        return Civil::daysFromCivil($this->year, $this->month, $this->day);
    }

    public function getDayOfWeek(): Weekday
    {
        return Weekday::fromIsoNumber(Civil::weekdayIndex($this->toDayNumber()) + 1);
    }

    /**
     * @param int<0, 23> $hour
     * @param int<0, 59> $minute
     * @param int<0, 59> $second
     *
     * @throws InvalidValueException
     */
    public function atTime(
        int $hour,
        int $minute = 0,
        int $second = 0,
    ): FloatingDateTime
    {
        return FloatingDateTime::of($this->year, $this->month, $this->day, $hour, $minute, $second);
    }

    /**
     * The first instant of this date in the time zone. A midnight that does not exist (DST gap) resolves
     * as RFC 5545 section 3.3.5 requires.
     */
    public function atStartOfDayIn(DateTimeZone $timeZone): DateTimeImmutable
    {
        $unix = (new TimeZoneResolver($timeZone))->localToUnix($this->toDayNumber() * Civil::SECONDS_PER_DAY);

        return (new DateTimeImmutable('@' . $unix))->setTimezone($timeZone);
    }

    /**
     * @return int<-1, 1>
     */
    public function compareTo(self $other): int
    {
        return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * ISO 8601 extended format, for example 2026-01-31.
     */
    public function toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

}
