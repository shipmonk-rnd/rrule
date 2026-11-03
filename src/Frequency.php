<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

/**
 * The FREQ rule part (RFC 5545 section 3.3.10).
 *
 * @api
 */
enum Frequency: string
{

    case Secondly = 'SECONDLY';
    case Minutely = 'MINUTELY';
    case Hourly = 'HOURLY';
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case Monthly = 'MONTHLY';
    case Yearly = 'YEARLY';

}
