<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Corpus;

/**
 * Corpus cases where the source disagrees with this library, with the reason for each.
 *
 * Modes:
 * - "reject": the input breaks RFC 5545, the strict parser must reject it (and it is not expanded);
 * - "accept": the source rejects input that the RFC allows; the library must accept it;
 * - "naive": the source expectation follows another interpretation; the test compares with
 *   {@see \ShipMonk\RRuleTests\Reference\NaiveExpander} (the JSCalendar 2.0 algorithm) instead;
 * - a list: the expected instances under this library's semantics.
 *
 * The S-numbers refer to the semantics decisions in the README.
 */
final class CorpusDeviations
{

    /**
     * @return array<string, array{string, 'reject'|'accept'|'naive'|list<string>}> case id => [reason, mode]
     */
    public static function get(): array
    {
        return [
            'dateutil-005' => [
                'S9: YEARLY with BYMONTHDAY and no BYMONTH takes the month from DTSTART (JSCalendar 2.0 implied parts, libical); dateutil uses every month',
                'naive',
            ],
            'dateutil-013' => [
                'S9: YEARLY with BYMONTHDAY and no BYMONTH takes the month from DTSTART (JSCalendar 2.0 implied parts, libical); dateutil uses every month',
                'naive',
            ],
            'dateutil-019' => [
                'S10: YEARLY with BYWEEKNO and no BYDAY/BYMONTHDAY takes the weekday from DTSTART (JSCalendar 2.0 implied parts, libical)',
                'naive',
            ],
            'dateutil-031' => [
                'S9: YEARLY with BYMONTHDAY and no BYMONTH takes the month from DTSTART (JSCalendar 2.0 implied parts, libical); dateutil uses every month',
                'naive',
            ],
            'dateutil-046' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-047' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-048' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-049' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-050' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-051' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-052' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-053' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-054' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MONTHLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-067' => [
                'RFC 5545 section 3.3.10 does not allow BYMONTHDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-068' => [
                'RFC 5545 section 3.3.10 does not allow BYMONTHDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-073' => [
                'RFC 5545 section 3.3.10 does not allow BYMONTHDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-074' => [
                'RFC 5545 section 3.3.10 does not allow BYMONTHDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-075' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-076' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-077' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-078' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-079' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-080' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-081' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-082' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-083' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=WEEKLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-104' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-105' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-106' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-107' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-108' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-109' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-110' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-111' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-112' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=DAILY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-137' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=HOURLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-138' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=HOURLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-139' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=HOURLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-140' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=HOURLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-141' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=HOURLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-166' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MINUTELY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-167' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MINUTELY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-168' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MINUTELY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-169' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MINUTELY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-170' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=MINUTELY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-195' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=SECONDLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-196' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=SECONDLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-197' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=SECONDLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-198' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=SECONDLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-199' => [
                'RFC 5545 section 3.3.10 does not allow BYWEEKNO with FREQ=SECONDLY; the strict parser rejects the rule (dateutil applies it as a filter)',
                'reject',
            ],
            'dateutil-212' => [
                'S6: UNTIL must have the value type of DTSTART (RFC 5545 section 3.3.10); a DATE UNTIL with a DATE-TIME start is rejected by the strict parser',
                'reject',
            ],
            'dateutil-invalid-001' => [
                'The rule is valid in RFC 5545 although no period after DTSTART can meet its BYxxx values; dateutil rejects it, this library returns only DTSTART',
                'accept',
            ],
            'dateutil-invalid-002' => [
                'The rule is valid in RFC 5545 although no period after DTSTART can meet its BYxxx values; dateutil rejects it, this library returns only DTSTART',
                'accept',
            ],
            'dateutil-invalid-003' => [
                'The rule is valid in RFC 5545 although no period after DTSTART can meet its BYxxx values; dateutil rejects it, this library returns only DTSTART',
                'accept',
            ],
            'dateutil-invalid-004' => [
                'The rule is valid in RFC 5545 although no period after DTSTART can meet its BYxxx values; dateutil rejects it, this library returns only DTSTART',
                'accept',
            ],
            'dateutil-invalid-005' => [
                'The rule is valid in RFC 5545 although no period after DTSTART can meet its BYxxx values; dateutil rejects it, this library returns only DTSTART',
                'accept',
            ],
            'dateutil-invalid-006' => [
                'The rule is valid in RFC 5545 although no period after DTSTART can meet its BYxxx values; dateutil rejects it, this library returns only DTSTART',
                'accept',
            ],
            'ical.net-002' => [
                'S13: INTERVAL counts calendar years, as the JSCalendar 2.0 yearly period is the calendar year; libical and Ical.Net count ISO week-numbering years',
                'naive',
            ],
            'ical.net-003' => [
                'S13: INTERVAL counts calendar years, as the JSCalendar 2.0 yearly period is the calendar year; libical and Ical.Net count ISO week-numbering years',
                'naive',
            ],
            'ical.net-005' => [
                'S13: INTERVAL counts calendar years (JSCalendar 2.0); libical and Ical.Net count ISO week-numbering years',
                ['20241231', '20270104', '20270105', '20300101', '20301230', '20301231'],
            ],
            'ical.net-067' => [
                'RFC 5545 section 3.3.10: a BYDAY ordinal (ordwk) is 1 to 53; "0MO" is invalid and the strict parser rejects it (Ical.Net ignores the value)',
                'reject',
            ],
            'ical.net-068' => [
                'RFC 5545 section 3.3.10: a BYDAY ordinal (ordwk) is 1 to 53; "0MO" is invalid and the strict parser rejects it (Ical.Net ignores the value)',
                'reject',
            ],
            'ical.net-069' => [
                'RFC 5545 section 3.3.10: a BYDAY ordinal (ordwk) is 1 to 53; "0MO" is invalid and the strict parser rejects it (Ical.Net ignores the value)',
                'reject',
            ],
            'libical-149' => [
                'S13: INTERVAL counts calendar years, as the JSCalendar 2.0 yearly period is the calendar year; libical and Ical.Net count ISO week-numbering years',
                'naive',
            ],
            'libical-150' => [
                'S13: INTERVAL counts calendar years, as the JSCalendar 2.0 yearly period is the calendar year; libical and Ical.Net count ISO week-numbering years',
                'naive',
            ],
            'libical-152' => [
                'S13: INTERVAL counts calendar years (JSCalendar 2.0); libical and Ical.Net count ISO week-numbering years',
                ['20241231', '20270104', '20270105', '20300101', '20301230', '20301231'],
            ],
            'libical-168' => [
                'S2: SKIP adjusts only invalid days of the month; a BYYEARDAY value that does not exist in a year matches nothing (RFC 7529 processing order, JSCalendar 2.0 "skip does not adjust byYearDay"); libical moves it',
                'naive',
            ],
            'libical-169' => [
                'S2: SKIP adjusts only invalid days of the month; a BYYEARDAY value that does not exist in a year matches nothing (RFC 7529 processing order, JSCalendar 2.0 "skip does not adjust byYearDay"); libical moves it',
                'naive',
            ],
            'libical-170' => [
                'S2: SKIP adjusts only invalid days of the month; a BYYEARDAY value that does not exist in a year matches nothing (RFC 7529 processing order, JSCalendar 2.0 "skip does not adjust byYearDay"); libical moves it',
                'naive',
            ],
            'libical-171' => [
                'S2: SKIP adjusts only invalid days of the month; a BYYEARDAY value that does not exist in a year matches nothing (RFC 7529 processing order, JSCalendar 2.0 "skip does not adjust byYearDay"); libical moves it',
                'naive',
            ],
            'mozilla-002' => [
                'S1: a non-matching DTSTART is the first occurrence and counts for COUNT (RFC 5545 section 3.3.10); Mozilla returns it in addition to COUNT',
                'naive',
            ],
            'mozilla-018' => [
                'S1: a non-matching DTSTART is the first occurrence and counts for COUNT (RFC 5545 section 3.3.10); Mozilla returns it in addition to COUNT',
                ['20150202T080000Z', '20150530T080000Z', '20150829T080000Z', '20151031T080000Z', '20160130T080000Z', '20160430T080000Z'],
            ],
            'mozilla-019' => [
                'S1: a non-matching DTSTART is the first occurrence and counts for COUNT (RFC 5545 section 3.3.10); Mozilla returns it in addition to COUNT',
                ['20150202T080000Z', '20151030T080000Z', '20160429T080000Z', '20161230T080000Z', '20170630T080000Z', '20171229T080000Z'],
            ],
            'mozilla-021' => [
                'S1: a non-matching DTSTART is the first occurrence and counts for COUNT (RFC 5545 section 3.3.10); Mozilla returns it in addition to COUNT',
                ['20150301T080000Z', '20150309T080000Z', '20150313T080000Z', '20150323T080000Z'],
            ],
            'mozilla-022' => [
                'S1: a non-matching DTSTART is the first occurrence and counts for COUNT (RFC 5545 section 3.3.10); Mozilla returns it in addition to COUNT',
                ['20150401T080000Z', '20150403T080000Z', '20150413T080000Z', '20150417T080000Z'],
            ],
            'mozilla-023' => [
                'S1: a non-matching DTSTART is the first occurrence and counts for COUNT (RFC 5545 section 3.3.10); Mozilla returns it in addition to COUNT',
                ['20150401T080000Z', '20150411T080000Z', '20150413T080000Z', '20150425T080000Z'],
            ],
            'mozilla-024' => [
                'S1: a non-matching DTSTART is the first occurrence and counts for COUNT (RFC 5545 section 3.3.10); Mozilla returns it in addition to COUNT',
                ['20150228T080000Z', '20150301T080000Z', '20150313T080000Z', '20150315T080000Z', '20150327T080000Z', '20150329T080000Z', '20150403T080000Z', '20150405T080000Z', '20150417T080000Z'],
            ],
            'pycalendar-001' => [
                'S10: YEARLY with BYWEEKNO and no BYDAY/BYMONTHDAY takes the weekday from DTSTART (JSCalendar 2.0 implied parts, libical)',
                'naive',
            ],
            'sabre-027' => [
                'S3: a nonexistent local time (DST gap) moves forward with the offset before the gap (RFC 5545 erratum 4271, section 3.3.5); sabre drops it',
                ['20230311T020000', '20230311T140000', '20230312T020000', '20230312T140000', '20230325T020000', '20230325T140000', '20230326T030000', '20230326T140000', '20230408T020000', '20230408T140000', '20230409T020000'],
            ],
            'sabre-063' => [
                'BYYEARDAY=-366 exists only in leap years (January 1); sabre returns December 31 in common years',
                ['20010101T145311Z', '20040101T145311Z', '20080101T145311Z', '20120101T145311Z', '20160101T145311Z', '20200101T145311Z', '20240101T145311Z', '20280101T145311Z'],
            ],
            'sabre-075' => [
                'S12: BYWEEKNO=-20 is ISO week 33 of 2012, which starts on 2012-08-13; sabre is one week early',
                ['20110902T000000Z', '20120814T000000Z', '20120817T000000Z', '20130813T000000Z', '20130816T000000Z', '20140812T000000Z', '20140815T000000Z', '20150818T000000Z', '20150821T000000Z', '20160816T000000Z', '20160819T000000Z', '20170815T000000Z'],
            ],
            'sabre-090' => [
                'RFC 5545 section 3.3.10 does not allow BYYEARDAY and BYWEEKNO with FREQ=DAILY; the strict parser rejects the rule (sabre ignores them)',
                'reject',
            ],
        ];
    }

}
