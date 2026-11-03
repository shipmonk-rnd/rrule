<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

use RuntimeException;

/**
 * The base class of all exceptions that the library throws for invalid input or unsatisfiable requests.
 *
 * @api
 */
abstract class RecurrenceException extends RuntimeException
{

}
