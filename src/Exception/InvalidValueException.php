<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

use RuntimeException;

/**
 * A date, time, count or weekday ordinal is out of its valid range, or a time zone cannot be used.
 *
 * @api
 */
final class InvalidValueException extends RuntimeException implements RecurrenceException
{

}
