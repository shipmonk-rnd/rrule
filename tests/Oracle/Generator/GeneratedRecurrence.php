<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle\Generator;

use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRuleTests\Oracle\DtStart;

/**
 * One generated case: a start and a rule. $rule is built directly from the same parts as $rrule,
 * so it does not depend on the parser.
 */
final class GeneratedRecurrence
{

    public function __construct(
        public readonly DtStart $dtStart,
        public readonly string $rrule,
        public readonly RecurrenceRule $rule,
    )
    {
    }

    /**
     * The content lines, for example "DTSTART;TZID=Europe/Prague:20260101T090000\nRRULE:FREQ=DAILY".
     */
    public function toRecurrenceText(): string
    {
        return $this->dtStart->toContentLine() . "\nRRULE:" . $this->rrule;
    }

    public function toString(): string
    {
        return $this->dtStart->toString() . ' ' . $this->rrule;
    }

}
