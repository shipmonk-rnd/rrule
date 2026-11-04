<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

use LogicException;

/**
 * The search for the next occurrence examined more periods than the limit allows
 * ({@see \ShipMonk\RRule\Recurrence::withSearchLimit()}). The answer is unknown; it is not "no more occurrences".
 *
 * Every rule is proven to terminate, so this happens only for rules whose occurrences are extremely sparse.
 * It is unchecked because it can come from lazy iteration, where no caller declares it.
 *
 * @api
 */
final class SearchLimitExceededException extends LogicException implements RecurrenceException
{

}
