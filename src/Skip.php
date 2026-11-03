<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

/**
 * The SKIP rule part (RFC 7529 section 4.1): what happens to a generated date that does not exist,
 * for example February 30.
 *
 * @api
 */
enum Skip: string
{

    /**
     * The date is ignored. This is the RFC 5545 behaviour.
     */
    case Omit = 'OMIT';

    /**
     * The date moves to the last valid day before it (February 30 becomes February 28 or 29).
     */
    case Backward = 'BACKWARD';

    /**
     * The date moves to the first valid day after it (February 30 becomes March 1).
     */
    case Forward = 'FORWARD';

}
