<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use ShipMonk\RRule\Exception\InvalidValueException;
use function sprintf;

/**
 * One BYDAY value (RFC 5545 "weekdaynum"): a weekday with an optional ordinal, for example MO, 1FR or -1SU.
 *
 * @api
 */
final class WeekdayNum
{

    /**
     * @param int<-53, -1>|int<1, 53>|null $ordinal the nth (or nth last) such weekday of the month or year
     */
    private function __construct(
        public readonly Weekday $weekday,
        public readonly ?int $ordinal,
    )
    {
    }

    /**
     * @throws InvalidValueException
     */
    public static function of(
        Weekday $weekday,
        ?int $ordinal = null,
    ): self
    {
        if ($ordinal !== null && ($ordinal === 0 || $ordinal < -53 || $ordinal > 53)) {
            throw new InvalidValueException(sprintf('Invalid BYDAY ordinal %d (allowed: -53 to -1 and 1 to 53)', $ordinal));
        }

        /** @var int<-53, -1>|int<1, 53>|null $ordinal */
        return new self($weekday, $ordinal);
    }

    /**
     * Every such weekday, without an ordinal (for example MO).
     */
    public static function every(Weekday $weekday): self
    {
        return new self($weekday, null);
    }

    public function equals(self $other): bool
    {
        return $this->weekday === $other->weekday && $this->ordinal === $other->ordinal;
    }

    /**
     * The RFC 5545 form, for example MO, 2TU or -1FR.
     */
    public function toString(): string
    {
        return ($this->ordinal === null ? '' : (string) $this->ordinal) . $this->weekday->value;
    }

}
