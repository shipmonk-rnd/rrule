<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Exception;

/**
 * The RFC 5545 or RFC 7529 requirement that a recurrence rule does not meet.
 *
 * @api
 */
enum RuleViolation: string
{

    case IntervalNotPositive = 'interval_not_positive';
    case ValueOutOfRange = 'value_out_of_range';
    case ByWeekNoRequiresYearly = 'by_week_no_requires_yearly';
    case ByYearDayNotAllowed = 'by_year_day_not_allowed';
    case ByMonthDayNotAllowedWithWeekly = 'by_month_day_not_allowed_with_weekly';
    case ByDayOrdinalNotAllowed = 'by_day_ordinal_not_allowed';
    case BySetPosRequiresAnotherByPart = 'by_set_pos_requires_another_by_part';
    case SkipRequiresCalendarScale = 'skip_requires_calendar_scale';

}
