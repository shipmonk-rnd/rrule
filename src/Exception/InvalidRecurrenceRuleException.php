<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

use RuntimeException;

/**
 * The rule breaks a requirement of RFC 5545 section 3.3.10 or RFC 7529.
 *
 * @api
 */
final class InvalidRecurrenceRuleException extends RuntimeException implements RecurrenceException
{

    public function __construct(
        public readonly RuleViolation $violation,
        string $message,
    )
    {
        parent::__construct($message);
    }

}
