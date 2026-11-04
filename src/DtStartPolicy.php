<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

/**
 * Controls whether the start value is an occurrence when it does not match the rules.
 *
 * @api
 */
enum DtStartPolicy
{

    /**
     * The start is always the first occurrence and counts for COUNT (RFC 5545 sections 3.3.10 and 3.8.5.3,
     * JSCalendar). This is the default.
     */
    case Always;

    /**
     * The start is an occurrence only when the rules produce it. This is the behaviour of python-dateutil,
     * rrule.js and libical. A recurrence without rules still has the start as its only occurrence.
     */
    case OnlyIfMatching;

}
