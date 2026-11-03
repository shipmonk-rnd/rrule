<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

/**
 * The start, the rules and the dates of a recurrence do not fit together, for example an UNTIL value type
 * that differs from the start value type (RFC 5545 section 3.3.10).
 *
 * @api
 */
final class InvalidRecurrenceException extends RecurrenceException
{

}
