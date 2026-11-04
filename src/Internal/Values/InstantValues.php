<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Internal\Civil;
use ShipMonk\RRule\Internal\TimeZoneResolver;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
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
    public static function forTimeZone(DateTimeZone $timeZone): self
    {
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
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): DateTimeImmutable
    {
        if (!$value instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(sprintf('The %s value must be a DateTimeImmutable, because the start is a date-time with a time zone', $name));
        }

        return $this->fromPosition($value->getTimestamp());
    }

    public function acceptStored(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): DateTimeImmutable
    {
        $accepted = $this->accept($value, $name);
        $unix = $accepted->getTimestamp();
        $local = (new TimeZoneResolver($this->timeZone))->unixToLocal($unix);

        foreach ([$unix, $local] as $seconds) {
            $year = Civil::civilFromDays(Civil::floorDiv($seconds, Civil::SECONDS_PER_DAY))[0];

            if ($year < Civil::MIN_YEAR || $year > Civil::MAX_YEAR) {
                throw new InvalidRecurrenceException(sprintf(
                    'The %s value %s is outside the years %d to %d in UTC or in the zone of the start, so RFC 5545 text cannot express it',
                    $name,
                    $accepted->format('Y-m-d\\TH:i:sP'),
                    Civil::MIN_YEAR,
                    Civil::MAX_YEAR,
                ));
            }
        }

        return $accepted;
    }

    public function toPosition(LocalDate|LocalDateTime|DateTimeImmutable $value): int
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
