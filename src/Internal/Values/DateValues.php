<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use LogicException;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\Civil;
use ShipMonk\RRule\Internal\ValueKind;
use function sprintf;

/**
 * @implements ValueConverter<CalendarDate>
 *
 * @internal
 */
final class DateValues implements ValueConverter
{

    public function getKind(): ValueKind
    {
        return ValueKind::Date;
    }

    public function accept(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): CalendarDate
    {
        if (!$value instanceof CalendarDate) {
            throw new InvalidArgumentException(sprintf('The %s value must be a CalendarDate, because the start is a date', $name));
        }

        return $value;
    }

    public function acceptStored(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): CalendarDate
    {
        return $this->accept($value, $name);
    }

    public function toPosition(CalendarDate|FloatingDateTime|DateTimeImmutable $value): int
    {
        if (!$value instanceof CalendarDate) {
            throw new LogicException('Expected CalendarDate');
        }

        return $value->toDayNumber() * Civil::SECONDS_PER_DAY;
    }

    public function fromPosition(int $position): CalendarDate
    {
        return CalendarDate::fromDayNumber(Civil::floorDiv($position, Civil::SECONDS_PER_DAY));
    }

}
