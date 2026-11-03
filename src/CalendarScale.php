<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

/**
 * The RSCALE rule part (RFC 7529 section 4.1). Only the Gregorian calendar is supported.
 *
 * @api
 */
enum CalendarScale: string
{

    case Gregorian = 'GREGORIAN';

}
