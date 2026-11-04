<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Parser;

/**
 * @api
 */
enum ParseMode
{

    /**
     * Accepts only what RFC 5545 and RFC 7529 allow (names are case-insensitive, as RFC 5545 section 3.1 says).
     */
    case Strict;

    /**
     * Also accepts a closed list of real-world deviations (Google, Outlook, rrule.js, dateutil output).
     * Each accepted deviation adds a {@see ParseWarning} to the result.
     */
    case Lenient;

}
