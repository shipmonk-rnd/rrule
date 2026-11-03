<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use LogicException;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;

/**
 * @internal
 */
final class RuleSerializer
{

    public static function serializeRule(RecurrenceRule $rule): string
    {
        throw new LogicException('Not implemented: ' . $rule->frequency->value);
    }

    /**
     * @param Recurrence<*> $recurrence
     */
    public static function serializeRecurrence(
        Recurrence $recurrence,
        string $lineBreak,
    ): string
    {
        throw new LogicException('Not implemented: ' . $lineBreak . $recurrence->getKind()->name);
    }

}
