<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle\Libical;

use RuntimeException;

/**
 * libical rejected the input. The message contains libical's error string, for example "MALFORMEDDATA".
 */
final class LibicalException extends RuntimeException
{

    public function __construct(
        string $message,
        public readonly string $libicalError,
    )
    {
        parent::__construct($message);
    }

}
