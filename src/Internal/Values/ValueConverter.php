<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;

/**
 * Converts the values of one recurrence between their public type and the integer positions the engine uses:
 * local seconds for dates and local date-times, Unix seconds for instants.
 *
 * @template T of LocalDate|LocalDateTime|DateTimeImmutable
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
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): LocalDate|LocalDateTime|DateTimeImmutable;

    /**
     * Like {@see self::accept()}, for a value that the recurrence stores (the start, RDATE, EXDATE): it must also
     * have an RFC 5545 text form, so its year is from 1 to 9999.
     *
     * @return T
     *
     * @throws InvalidRecurrenceException
     */
    public function acceptStored(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): LocalDate|LocalDateTime|DateTimeImmutable;

    /**
     * @param T $value
     */
    public function toPosition(LocalDate|LocalDateTime|DateTimeImmutable $value): int;

    /**
     * @return T
     */
    public function fromPosition(int $position): LocalDate|LocalDateTime|DateTimeImmutable;

}
