<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use DateTimeImmutable;
use DateTimeZone;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\FloatingDateTime;
use function in_array;
use function sprintf;

/**
 * The four forms of RFC 5545 date and date-time values.
 *
 * @internal
 */
enum ValueKind
{

    /**
     * DATE (section 3.3.4).
     */
    case Date;

    /**
     * DATE-TIME without a time zone (section 3.3.5, form #1).
     */
    case Floating;

    /**
     * DATE-TIME in UTC (section 3.3.5, form #2).
     */
    case Utc;

    /**
     * DATE-TIME with a time zone reference (section 3.3.5, form #3).
     */
    case Zoned;

    /**
     * @throws InvalidValueException
     */
    public static function of(CalendarDate|FloatingDateTime|DateTimeImmutable $value): self
    {
        if ($value instanceof CalendarDate) {
            return self::Date;
        }

        if ($value instanceof FloatingDateTime) {
            return self::Floating;
        }

        return self::ofTimeZone($value->getTimezone());
    }

    /**
     * @throws InvalidValueException
     */
    public static function ofTimeZone(DateTimeZone $timeZone): self
    {
        $name = $timeZone->getName();

        if (in_array($name, ['UTC', 'Z', '+00:00', '-00:00'], true)) {
            return self::Utc;
        }

        if (in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return self::Zoned;
        }

        throw new InvalidValueException(sprintf(
            'Time zone "%s" is not supported: use a named IANA zone (for example Europe/Prague) or UTC, because RFC 5545 references a time zone by its identifier',
            $name,
        ));
    }

    public function isDateTime(): bool
    {
        return $this !== self::Date;
    }

    public function hasInstant(): bool
    {
        return $this === self::Utc || $this === self::Zoned;
    }

}
