<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Internal\Civil;
use ShipMonk\RRule\Internal\TimeZoneResolver;
use function intdiv;
use function sprintf;

/**
 * A date and time without a time zone: the RFC 5545 "floating" DATE-TIME (section 3.3.5, form #1).
 * It means the same wall-clock time in every time zone.
 *
 * @api
 */
final class FloatingDateTime
{

    /**
     * @param int<1, 9999> $year
     * @param int<1, 12> $month
     * @param int<1, 31> $day
     * @param int<0, 23> $hour
     * @param int<0, 59> $minute
     * @param int<0, 59> $second
     */
    private function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
        public readonly int $hour,
        public readonly int $minute,
        public readonly int $second,
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
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
    ): self
    {
        if (
            !Civil::isValidDate($year, $month, $day)
            || $hour < 0
            || $hour > 23
            || $minute < 0
            || $minute > 59
            || $second < 0
            || $second > 59
        ) {
            throw new InvalidValueException(sprintf(
                'Invalid date-time %04d-%02d-%02dT%02d:%02d:%02d (years 1 to 9999 are supported)',
                $year,
                $month,
                $day,
                $hour,
                $minute,
                $second,
            ));
        }

        /** @var int<1, 9999> $year */
        /** @var int<1, 12> $month */
        /** @var int<1, 31> $day */
        /** @var int<0, 23> $hour */
        /** @var int<0, 59> $minute */
        /** @var int<0, 59> $second */
        return new self($year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Takes the wall-clock date and time of the value, in its own time zone. Microseconds are dropped.
     *
     * @throws InvalidValueException
     */
    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return self::of(
            (int) $dateTime->format('Y'),
            (int) $dateTime->format('n'),
            (int) $dateTime->format('j'),
            (int) $dateTime->format('G'),
            (int) $dateTime->format('i'),
            (int) $dateTime->format('s'),
        );
    }

    /**
     * @internal
     */
    public static function fromLocalSeconds(int $localSeconds): self
    {
        $days = Civil::floorDiv($localSeconds, Civil::SECONDS_PER_DAY);
        $secondOfDay = $localSeconds - $days * Civil::SECONDS_PER_DAY;
        [$year, $month, $day] = Civil::civilFromDays($days);

        /** @var int<1, 9999> $year */
        /** @var int<0, 23> $hour */
        $hour = intdiv($secondOfDay, 3600);
        /** @var int<0, 59> $minute */
        $minute = intdiv($secondOfDay % 3600, 60);
        /** @var int<0, 59> $second */
        $second = $secondOfDay % 60;

        return new self($year, $month, $day, $hour, $minute, $second);
    }

    /**
     * @internal
     */
    public function toLocalSeconds(): int
    {
        return Civil::localSeconds(
            Civil::daysFromCivil($this->year, $this->month, $this->day),
            $this->hour,
            $this->minute,
            $this->second,
        );
    }

    public function getDate(): CalendarDate
    {
        return CalendarDate::fromDayNumber(Civil::daysFromCivil($this->year, $this->month, $this->day));
    }

    /**
     * The instant of this wall-clock time in the time zone, resolved as RFC 5545 section 3.3.5 requires:
     * a time that occurs twice (DST overlap) is its first occurrence, and a time that does not exist (DST gap)
     * uses the UTC offset from before the gap. PHP's own DateTime constructor does not do this reliably.
     */
    public function atTimeZone(DateTimeZone $timeZone): DateTimeImmutable
    {
        $unix = (new TimeZoneResolver($timeZone))->localToUnix($this->toLocalSeconds());

        return (new DateTimeImmutable('@' . $unix))->setTimezone($timeZone);
    }

    /**
     * @return int<-1, 1>
     */
    public function compareTo(self $other): int
    {
        return $this->toLocalSeconds() <=> $other->toLocalSeconds();
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
     * ISO 8601 extended format, for example 2026-01-31T09:00:00.
     */
    public function toString(): string
    {
        return sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $this->year, $this->month, $this->day, $this->hour, $this->minute, $this->second);
    }

}
