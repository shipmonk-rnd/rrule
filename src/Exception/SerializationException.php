<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

use LogicException;

/**
 * The recurrence has no RFC 5545 text form that keeps its meaning. Example: a zoned start in the second pass of
 * a DST overlap, because DTSTART;TZID can express only the first pass (RFC 5545 section 3.3.5).
 *
 * @api
 */
final class SerializationException extends LogicException implements RecurrenceException
{

}
