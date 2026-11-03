<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\ValueKind;
use function sprintf;

/**
 * Instants of a UTC or zoned recurrence. Values are returned in the zone of the start, without microseconds.
 *
 * @implements ValueConverter<DateTimeImmutable>
 *
 * @internal
 */
final class InstantValues implements ValueConverter
{

    public function __construct(
        private readonly DateTimeZone $timeZone,
        private readonly ValueKind $kind,
    )
    {
    }

    /**
     * @throws InvalidValueException
     */
    public static function forStart(DateTimeImmutable $start): self
    {
        $timeZone = $start->getTimezone();

        return new self($timeZone, ValueKind::ofTimeZone($timeZone));
    }

    public function getKind(): ValueKind
    {
        return $this->kind;
    }

    public function getTimeZone(): DateTimeZone
    {
        return $this->timeZone;
    }

    public function accept(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): DateTimeImmutable
    {
        if (!$value instanceof DateTimeImmutable) {
            throw new InvalidRecurrenceException(sprintf('The %s value must be a DateTimeImmutable, because the start is a date-time with a time zone', $name));
        }

        return $this->fromPosition($value->getTimestamp());
    }

    public function dateAsValue(CalendarDate $date): null
    {
        return null;
    }

    public function toPosition(CalendarDate|FloatingDateTime|DateTimeImmutable $value): int
    {
        if (!$value instanceof DateTimeImmutable) {
            throw new LogicException('Expected DateTimeImmutable');
        }

        return $value->getTimestamp();
    }

    public function fromPosition(int $position): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $position))->setTimezone($this->timeZone);
    }

}
