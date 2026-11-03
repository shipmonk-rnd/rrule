<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use InvalidArgumentException;
use function preg_match;
use function sprintf;

/**
 * An RFC 5545 DTSTART value in one of four forms. The string form is the property value with its TZID parameter:
 *
 * - DATE: 20260101
 * - floating DATE-TIME: 20260101T090000
 * - UTC DATE-TIME: 20260101T090000Z
 * - zoned DATE-TIME: TZID=Europe/Prague:20260101T090000
 *
 * Occurrences that the oracles return use the same forms, without the TZID (zoned values are local wall time).
 */
final class DtStart
{

    private function __construct(
        public readonly DtStartKind $kind,
        public readonly string $value,
        public readonly ?string $timeZone,
    )
    {
    }

    public static function fromString(string $dtStart): self
    {
        if (preg_match('~^TZID=([^:;]+):(\d{8}T\d{6})$~D', $dtStart, $matches) === 1) {
            return new self(DtStartKind::Zoned, $matches[2], $matches[1]);
        }

        if (preg_match('~^\d{8}$~D', $dtStart) === 1) {
            return new self(DtStartKind::Date, $dtStart, null);
        }

        if (preg_match('~^\d{8}T\d{6}$~D', $dtStart) === 1) {
            return new self(DtStartKind::Floating, $dtStart, null);
        }

        if (preg_match('~^\d{8}T\d{6}Z$~D', $dtStart) === 1) {
            return new self(DtStartKind::Utc, $dtStart, null);
        }

        throw new InvalidArgumentException(sprintf('Invalid DTSTART "%s"', $dtStart));
    }

    public static function date(string $value): self
    {
        return self::checked(DtStartKind::Date, $value);
    }

    public static function floating(string $value): self
    {
        return self::checked(DtStartKind::Floating, $value);
    }

    public static function utc(string $value): self
    {
        return self::checked(DtStartKind::Utc, $value);
    }

    public static function zoned(
        string $timeZone,
        string $value,
    ): self
    {
        return self::fromString(sprintf('TZID=%s:%s', $timeZone, $value));
    }

    public function toString(): string
    {
        return $this->timeZone === null ? $this->value : sprintf('TZID=%s:%s', $this->timeZone, $this->value);
    }

    /**
     * The full content line, for example "DTSTART;VALUE=DATE:20260101".
     */
    public function toContentLine(): string
    {
        return match ($this->kind) {
            DtStartKind::Date => 'DTSTART;VALUE=DATE:' . $this->value,
            DtStartKind::Floating, DtStartKind::Utc => 'DTSTART:' . $this->value,
            DtStartKind::Zoned => sprintf('DTSTART;TZID=%s:%s', $this->timeZone, $this->value),
        };
    }

    private static function checked(
        DtStartKind $kind,
        string $value,
    ): self
    {
        $dtStart = self::fromString($value);

        if ($dtStart->kind !== $kind) {
            throw new InvalidArgumentException(sprintf('"%s" is not a %s DTSTART', $value, $kind->name));
        }

        return $dtStart;
    }

}
