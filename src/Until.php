<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use DateTimeImmutable;
use DateTimeZone;
use ShipMonk\RRule\Exception\InvalidValueException;

/**
 * The UNTIL rule part: the inclusive end of the rule (RFC 5545 section 3.3.10).
 *
 * Its value type must match the start: a date for a date start, a local date-time for a local start,
 * and a UTC date-time for a UTC or zoned start.
 *
 * @api
 */
final class Until
{

    private function __construct(
        public readonly LocalDate|LocalDateTime|DateTimeImmutable $value,
    )
    {
    }

    public static function date(LocalDate $date): self
    {
        return new self($date);
    }

    public static function local(LocalDateTime $dateTime): self
    {
        return new self($dateTime);
    }

    /**
     * The instant is stored in UTC, as RFC 5545 requires for UNTIL with a UTC or zoned start.
     *
     * @throws InvalidValueException
     */
    public static function instant(DateTimeImmutable $instant): self
    {
        $utc = DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'));
        LocalDateTime::fromDateTime($utc); // validates the year range

        return new self($utc->setTime((int) $utc->format('G'), (int) $utc->format('i'), (int) $utc->format('s')));
    }

}
