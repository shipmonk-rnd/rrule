<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Parser;

/**
 * @api
 */
enum ParseWarningCode: string
{

    case UnknownRulePartIgnored = 'unknown_rule_part_ignored';
    case DuplicateRulePartIgnored = 'duplicate_rule_part_ignored';
    case EmptyRulePartIgnored = 'empty_rule_part_ignored';
    case ForbiddenRulePartIgnored = 'forbidden_rule_part_ignored';
    case CountWithUntilCountIgnored = 'count_with_until_count_ignored';
    case InvalidCountIgnored = 'invalid_count_ignored';
    case UntilValueTypeConverted = 'until_value_type_converted';
    case TimePartsIgnoredForDateStart = 'time_parts_ignored_for_date_start';
    case SkipWithoutCalendarScale = 'skip_without_calendar_scale';
    case StartInsideRule = 'start_inside_rule';
    case ExclusionRuleAccepted = 'exclusion_rule_accepted';
    case UnknownPropertyIgnored = 'unknown_property_ignored';
    case DateExclusionForDateTimeStart = 'date_exclusion_for_date_time_start';
    case DateAdditionForDateTimeStart = 'date_addition_for_date_time_start';
    case PeriodEndIgnored = 'period_end_ignored';
    case TimeZoneIgnoredForUtcValue = 'time_zone_ignored_for_utc_value';

}
