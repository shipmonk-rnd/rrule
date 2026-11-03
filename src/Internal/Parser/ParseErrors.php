<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

use ShipMonk\RRule\Exception\ParseException;
use Throwable;
use function sprintf;

/**
 * @internal
 */
final class ParseErrors
{

    /**
     * Prefixes the message with the line number, so that the line is visible also where only the message is logged.
     *
     * @param positive-int|null $lineNumber
     */
    public static function create(
        string $message,
        ?int $lineNumber,
        ?Throwable $previous = null,
    ): ParseException
    {
        return new ParseException(
            $lineNumber === null ? $message : sprintf('Line %d: %s', $lineNumber, $message),
            $lineNumber,
            $previous,
        );
    }

}
