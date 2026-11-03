<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

/**
 * The search for the next occurrence examined more periods than the configured limit allows.
 * This is different from "no more occurrences": the answer is unknown.
 *
 * @api
 */
final class SearchLimitExceededException extends RecurrenceException
{

}
