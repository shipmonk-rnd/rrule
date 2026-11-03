<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Parser;

/**
 * @api
 */
final class ParseWarning
{

    /**
     * @param positive-int|null $lineNumber the line in the input (after unfolding), when known
     */
    public function __construct(
        public readonly ParseWarningCode $code,
        public readonly string $message,
        public readonly ?int $lineNumber = null,
    )
    {
    }

}
