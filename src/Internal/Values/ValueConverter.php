<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\ValueKind;

/**
 * Converts the values of one recurrence between their public type and the integer positions the engine uses:
 * local seconds for dates and floating date-times, Unix seconds for instants.
 *
 * @template T of CalendarDate|FloatingDateTime|DateTimeImmutable
 *
 * @internal
 */
interface ValueConverter
{

    public function getKind(): ValueKind;

    /**
     * Checks that a value has the type of this recurrence and normalizes it (instants move to the start zone).
     *
     * @return T
     *
     * @throws InvalidRecurrenceException
     */
    public function accept(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): CalendarDate|FloatingDateTime|DateTimeImmutable;

    /**
     * The date as a value of this recurrence, or null when dates are not its values.
     *
     * @return T|null
     */
    public function dateAsValue(CalendarDate $date): CalendarDate|FloatingDateTime|DateTimeImmutable|null;

    /**
     * @param T $value
     */
    public function toPosition(CalendarDate|FloatingDateTime|DateTimeImmutable $value): int;

    /**
     * @return T
     */
    public function fromPosition(int $position): CalendarDate|FloatingDateTime|DateTimeImmutable;

}
