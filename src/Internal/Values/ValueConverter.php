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
     */
    public function accept(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): CalendarDate|FloatingDateTime|DateTimeImmutable;

    /**
     * Like {@see self::accept()}, for a value that the recurrence stores (the start, RDATE, EXDATE): it must also
     * have an RFC 5545 text form, so its year is from 1 to 9999.
     *
     * @return T
     *
     * @throws InvalidRecurrenceException
     */
    public function acceptStored(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): CalendarDate|FloatingDateTime|DateTimeImmutable;

    /**
     * @param T $value
     */
    public function toPosition(CalendarDate|FloatingDateTime|DateTimeImmutable $value): int;

    /**
     * @return T
     */
    public function fromPosition(int $position): CalendarDate|FloatingDateTime|DateTimeImmutable;

}
