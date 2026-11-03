<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use LogicException;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\ValueKind;
use function sprintf;

/**
 * @implements ValueConverter<FloatingDateTime>
 *
 * @internal
 */
final class FloatingValues implements ValueConverter
{

    public function getKind(): ValueKind
    {
        return ValueKind::Floating;
    }

    public function accept(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): FloatingDateTime
    {
        if (!$value instanceof FloatingDateTime) {
            throw new InvalidRecurrenceException(sprintf('The %s value must be a FloatingDateTime, because the start is a floating date-time', $name));
        }

        return $value;
    }

    public function dateAsValue(CalendarDate $date): ?CalendarDate
    {
        return null;
    }

    public function toPosition(CalendarDate|FloatingDateTime|DateTimeImmutable $value): int
    {
        if (!$value instanceof FloatingDateTime) {
            throw new LogicException('Expected FloatingDateTime');
        }

        return $value->toLocalSeconds();
    }

    public function fromPosition(int $position): FloatingDateTime
    {
        return FloatingDateTime::fromLocalSeconds($position);
    }

}
