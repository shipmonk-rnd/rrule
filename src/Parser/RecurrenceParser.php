<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Parser;

use DateTimeImmutable;
use LogicException;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;

/**
 * @api
 */
final class RecurrenceParser
{

    public function __construct(
        private readonly ParseMode $mode = ParseMode::Strict,
    )
    {
    }

    /**
     * Parses an RFC 5545 RECUR value ("FREQ=...;..."). A leading "RRULE:" is accepted.
     *
     * @return ParseResult<RecurrenceRule>
     * @throws ParseException
     */
    public function parseRule(string $value): ParseResult
    {
        throw new LogicException('Not implemented: ' . $value . $this->mode->name);
    }

    /**
     * Parses DTSTART, RRULE, RDATE, EXDATE (and in lenient mode EXRULE) content lines.
     *
     * @return ParseResult<Recurrence<CalendarDate>|Recurrence<FloatingDateTime>|Recurrence<DateTimeImmutable>>
     * @throws ParseException
     */
    public function parseRecurrence(string $text): ParseResult
    {
        throw new LogicException('Not implemented: ' . $text);
    }

}
