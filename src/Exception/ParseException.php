<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

use RuntimeException;
use Throwable;

/**
 * The text is not a valid RFC 5545 recurrence (or is outside what the parse mode accepts).
 *
 * @api
 */
final class ParseException extends RuntimeException implements RecurrenceException
{

    /**
     * @param positive-int|null $lineNumber the line in the input (after unfolding), when known
     */
    public function __construct(
        string $message,
        public readonly ?int $lineNumber = null,
        ?Throwable $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }

}
