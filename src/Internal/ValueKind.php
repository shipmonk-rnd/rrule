<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use DateTimeZone;
use ShipMonk\RRule\Exception\InvalidValueException;
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
     * IANA zones that PHP builds as fixed-offset abbreviations although they have DST (for example `new
     * DateTimeZone('CET')` is +01:00 in July). Since tzdata 2024b they are links to these zones.
     */
    public const DST_ABBREVIATION_ZONES = [
        'CET' => 'Europe/Brussels',
        'EET' => 'Europe/Athens',
        'MET' => 'Europe/Brussels',
        'WET' => 'Europe/Lisbon',
    ];

    /**
     * @throws InvalidValueException
     */
    public static function ofTimeZone(DateTimeZone $timeZone): self
    {
        $name = $timeZone->getName();

        if (self::isUtc($timeZone)) {
            return self::Utc;
        }

        if (isset(self::DST_ABBREVIATION_ZONES[$name])) {
            throw new InvalidValueException(sprintf(
                'Time zone "%s" is not supported: PHP treats it as a fixed UTC offset without DST, but the IANA zone has DST; use %s',
                $name,
                self::DST_ABBREVIATION_ZONES[$name],
            ));
        }

        if (in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return self::Zoned;
        }

        throw new InvalidValueException(sprintf(
            'Time zone "%s" is not supported: use a named IANA zone (for example Europe/Prague) or UTC, because RFC 5545 references a time zone by its identifier',
            $name,
        ));
    }

    public static function isUtc(DateTimeZone $timeZone): bool
    {
        return in_array($timeZone->getName(), ['UTC', 'Z', '+00:00', '-00:00'], true);
    }

}
