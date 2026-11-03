<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use ShipMonk\RRule\Exception\InvalidValueException;
use function sprintf;

/**
 * The COUNT rule part: the number of occurrences that the rule produces (RFC 5545 section 3.3.10).
 *
 * @api
 */
final class Count
{

    /**
     * @param positive-int $value
     */
    private function __construct(
        public readonly int $value,
    )
    {
    }

    /**
     * @throws InvalidValueException
     */
    public static function of(int $value): self
    {
        if ($value < 1) {
            throw new InvalidValueException(sprintf('COUNT must be a positive integer, %d given', $value));
        }

        return new self($value);
    }

}
