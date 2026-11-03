<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Parser;

/**
 * @template-covariant T
 *
 * @api
 */
final class ParseResult
{

    /**
     * @param T                  $value
     * @param list<ParseWarning> $warnings
     */
    public function __construct(
        public readonly mixed $value,
        public readonly array $warnings,
    )
    {
    }

}
