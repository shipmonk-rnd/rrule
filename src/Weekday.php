<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

/**
 * A day of the week as RFC 5545 writes it (MO to SU).
 *
 * @api
 */
enum Weekday: string
{

    case Monday = 'MO';
    case Tuesday = 'TU';
    case Wednesday = 'WE';
    case Thursday = 'TH';
    case Friday = 'FR';
    case Saturday = 'SA';
    case Sunday = 'SU';

    /**
     * @param int<1, 7> $isoNumber 1 is Monday, 7 is Sunday (ISO 8601)
     */
    public static function fromIsoNumber(int $isoNumber): self
    {
        return match ($isoNumber) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            7 => self::Sunday,
        };
    }

    /**
     * @return int<1, 7> 1 is Monday, 7 is Sunday (ISO 8601)
     */
    public function getIsoNumber(): int
    {
        return match ($this) {
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
            self::Sunday => 7,
        };
    }

}
