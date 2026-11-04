<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

use InvalidArgumentException as NativeInvalidArgumentException;

/**
 * An argument breaks the contract of the method, for example a value of another type than the start of the
 * recurrence, or a search limit below 1. It is unchecked, because it shows an error in the calling code,
 * not in the data.
 *
 * @api
 */
final class InvalidArgumentException extends NativeInvalidArgumentException implements RecurrenceException
{

}
