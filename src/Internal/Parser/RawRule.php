<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

/**
 * A RECUR value split into its parts, before the values are parsed.
 *
 * @internal
 */
final class RawRule
{

    /**
     * @param string                          $source     the RECUR value, for messages
     * @param array<string, non-empty-string> $parts      upper-case part name => value
     * @param non-empty-string|null           $start      the value of a DTSTART part (rrule.js form, lenient mode only)
     * @param positive-int|null               $lineNumber
     */
    public function __construct(
        public readonly string $source,
        public readonly array $parts,
        public readonly ?string $start,
        public readonly ?int $lineNumber,
    )
    {
    }

}
