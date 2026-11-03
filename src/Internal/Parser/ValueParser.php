<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

use DateTimeImmutable;
use DateTimeZone;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\ValueKind;
use Throwable;
use function preg_match;
use function sprintf;
use function strtr;

/**
 * Parses DATE (RFC 5545 section 3.3.4), DATE-TIME (3.3.5) and DURATION (3.3.6) values and TZID references.
 *
 * ABNF literals are case-insensitive (RFC 5234 section 2.3), so "t" and "z" are accepted as well.
 *
 * @internal
 */
final class ValueParser
{

    private const DATE_PATTERN = '~^(\d{4})(\d{2})(\d{2})\z~';
    private const DATE_TIME_PATTERN = '~^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)\z~i';
    private const DURATION_TIME = 'T(?:\d+H(?:\d+M(?:\d+S)?)?|\d+M(?:\d+S)?|\d+S)';
    private const DURATION_PATTERN = '~^\+?P(?:\d+W|\d+D(?:' . self::DURATION_TIME . ')?|' . self::DURATION_TIME . ')\z~i';

    /**
     * Upper-cases ASCII letters only. strtoupper() depends on the locale on PHP 8.1.
     */
    public static function upper(string $value): string
    {
        return strtr($value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    public static function isDate(string $value): bool
    {
        return preg_match(self::DATE_PATTERN, $value) === 1;
    }

    public static function isDateTime(string $value): bool
    {
        return preg_match(self::DATE_TIME_PATTERN, $value) === 1;
    }

    /**
     * A positive DURATION, as a PERIOD needs it (RFC 5545 section 3.3.9).
     */
    public static function isDuration(string $value): bool
    {
        return preg_match(self::DURATION_PATTERN, $value) === 1;
    }

    /**
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    public static function parseDate(
        string $value,
        string $name,
        ?int $lineNumber,
    ): CalendarDate
    {
        if (preg_match(self::DATE_PATTERN, $value, $matches) !== 1) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": expected a DATE (YYYYMMDD)', $name, $value), $lineNumber);
        }

        try {
            return CalendarDate::of((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        } catch (InvalidValueException $e) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": %s', $name, $value, $e->getMessage()), $lineNumber, $e);
        }
    }

    /**
     * A floating DATE-TIME, or a DateTimeImmutable in UTC when the value ends with Z.
     *
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    public static function parseDateTime(
        string $value,
        string $name,
        ?int $lineNumber,
    ): FloatingDateTime|DateTimeImmutable
    {
        if (preg_match(self::DATE_TIME_PATTERN, $value, $matches) !== 1) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": expected a DATE-TIME (YYYYMMDDTHHMMSS, optionally followed by Z)', $name, $value), $lineNumber);
        }

        try {
            $dateTime = FloatingDateTime::of(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                (int) $matches[4],
                (int) $matches[5],
                (int) $matches[6],
            );
        } catch (InvalidValueException $e) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": %s', $name, $value, $e->getMessage()), $lineNumber, $e);
        }

        return $matches[7] === '' ? $dateTime : $dateTime->atTimeZone(new DateTimeZone('UTC'));
    }

    /**
     * A DATE or a DATE-TIME, recognized by its form (UNTIL, and DTSTART inside a rule).
     *
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    public static function parseDateOrDateTime(
        string $value,
        string $name,
        ?int $lineNumber,
    ): CalendarDate|FloatingDateTime|DateTimeImmutable
    {
        if (self::isDate($value)) {
            return self::parseDate($value, $name, $lineNumber);
        }

        if (self::isDateTime($value)) {
            return self::parseDateTime($value, $name, $lineNumber);
        }

        throw ParseErrors::create(sprintf('Invalid %s value "%s": expected YYYYMMDD, YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ', $name, $value), $lineNumber);
    }

    /**
     * Accepts only identifiers that the model accepts (IANA names and UTC), checked before any conversion,
     * because the conversion of local time needs the transitions of a named zone.
     *
     * @param positive-int $lineNumber
     *
     * @throws ParseException
     */
    public static function parseTimeZone(
        string $timeZoneId,
        int $lineNumber,
    ): DateTimeZone
    {
        try {
            // TZID=CET means the IANA zone, which has DST; PHP would build a fixed-offset abbreviation
            $timeZone = new DateTimeZone(ValueKind::DST_ABBREVIATION_ZONES[$timeZoneId] ?? $timeZoneId);
        } catch (Throwable $e) {
            throw ParseErrors::create(sprintf('Unknown time zone "%s" in TZID', $timeZoneId), $lineNumber, $e);
        }

        try {
            ValueKind::ofTimeZone($timeZone);
        } catch (InvalidValueException $e) {
            throw ParseErrors::create('Invalid TZID: ' . $e->getMessage(), $lineNumber, $e);
        }

        return $timeZone;
    }

}
