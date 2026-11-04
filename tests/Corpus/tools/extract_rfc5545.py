"""Generate data/rfc5545-examples/cases.json from the RFC 5545 section 3.8.5.3 examples.

Usage: python3 -I tests/Corpus/tools/extract_rfc5545.py <path to rfc5545.txt>

The expected instances are hand-encoded from the RFC prose below. The tool reads the RFC text
only to check that every DTSTART, EXDATE and RRULE line below occurs in section 3.8.5.3.
Run verify_rfc5545.py afterwards: it recomputes every instance independently.
"""

import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'rfc5545-examples'
SOURCE_URL = 'https://www.rfc-editor.org/rfc/rfc5545#section-3.8.5.3'
LICENSE = 'BSD-3-Clause'
TZID = 'America/New_York'
OFFSETS = {'EDT': '-0400', 'EST': '-0500'}
MONTHS = {name: number for number, name in enumerate(
    ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'], 1)}

ERRATUM_3883 = ('RRULE:FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T210000Z', 'RRULE:FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T170000Z')
ELLIPSIS_NOTE = 'The RFC prose shortens this list with "..."; the elided values are the arithmetic continuation of the listed ones.'
NOT_SYNCHRONIZED_NOTE = (
    'DTSTART does not match the rule; the EXDATE removes it. Erratum 5920 (rejected) asked to synchronize DTSTART. '
    'The result does not depend on dtStartPolicy.'
)

# Each example: title, DTSTART (local time in America/New_York), RRULE alternatives,
# expected segments, truncated, notes, EXDATE values.
# A segment is (year, zone, 'Month d,d-d,d-d/step;Month ...') for 09:00 instances,
# or ('YYYYMMDD', zone, 'HH:MM,HH:MM-HH:MM/step') for instances within one day.
EXAMPLES = [
    ('Daily for 10 occurrences', '19970902T090000',
        ['FREQ=DAILY;COUNT=10'],
        [(1997, 'EDT', 'September 2-11')], False, None, []),
    ('Daily until December 24, 1997', '19970902T090000',
        ['FREQ=DAILY;UNTIL=19971224T000000Z'],
        [(1997, 'EDT', 'September 2-30;October 1-25'),
            (1997, 'EST', 'October 26-31;November 1-30;December 1-23')], False, None, []),
    ('Every other day - forever', '19970902T090000',
        ['FREQ=DAILY;INTERVAL=2'],
        [(1997, 'EDT', 'September 2-30/2;October 2-24/2'),
            (1997, 'EST', 'October 26-30/2;November 1-29/2;December 1,3')], True, {'info': ELLIPSIS_NOTE}, []),
    ('Every 10 days, 5 occurrences', '19970902T090000',
        ['FREQ=DAILY;INTERVAL=10;COUNT=5'],
        [(1997, 'EDT', 'September 2,12,22;October 2,12')], False, None, []),
    ('Every day in January, for 3 years', '19980101T090000',
        ['FREQ=YEARLY;UNTIL=20000131T140000Z;BYMONTH=1;BYDAY=SU,MO,TU,WE,TH,FR,SA',
            'FREQ=DAILY;UNTIL=20000131T140000Z;BYMONTH=1'],
        [(1998, 'EST', 'January 1-31'), (1999, 'EST', 'January 1-31'), (2000, 'EST', 'January 1-31')], False, None, []),
    ('Weekly for 10 occurrences', '19970902T090000',
        ['FREQ=WEEKLY;COUNT=10'],
        [(1997, 'EDT', 'September 2,9,16,23,30;October 7,14,21'),
            (1997, 'EST', 'October 28;November 4')], False, None, []),
    ('Weekly until December 24, 1997', '19970902T090000',
        ['FREQ=WEEKLY;UNTIL=19971224T000000Z'],
        [(1997, 'EDT', 'September 2,9,16,23,30;October 7,14,21'),
            (1997, 'EST', 'October 28;November 4,11,18,25;December 2,9,16,23')], False, None, []),
    ('Every other week - forever', '19970902T090000',
        ['FREQ=WEEKLY;INTERVAL=2;WKST=SU'],
        [(1997, 'EDT', 'September 2,16,30;October 14'),
            (1997, 'EST', 'October 28;November 11,25;December 9,23'),
            (1998, 'EST', 'January 6,20;February 3,17')], True, None, []),
    ('Weekly on Tuesday and Thursday for five weeks', '19970902T090000',
        ['FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH',
            'FREQ=WEEKLY;COUNT=10;WKST=SU;BYDAY=TU,TH'],
        [(1997, 'EDT', 'September 2,4,9,11,16,18,23,25,30;October 2')], False, None, []),
    ('Every other week on Monday, Wednesday, and Friday until December 24, 1997, starting on Monday, September 1, 1997',
        '19970901T090000',
        ['FREQ=WEEKLY;INTERVAL=2;UNTIL=19971224T000000Z;WKST=SU;BYDAY=MO,WE,FR'],
        [(1997, 'EDT', 'September 1,3,5,15,17,19,29;October 1,3,13,15,17'),
            (1997, 'EST', 'October 27,29,31;November 10,12,14,24,26,28;December 8,10,12,22')], False, None, []),
    ('Every other week on Tuesday and Thursday, for 8 occurrences', '19970902T090000',
        ['FREQ=WEEKLY;INTERVAL=2;COUNT=8;WKST=SU;BYDAY=TU,TH'],
        [(1997, 'EDT', 'September 2,4,16,18,30;October 2,14,16')], False, None, []),
    ('Monthly on the first Friday for 10 occurrences', '19970905T090000',
        ['FREQ=MONTHLY;COUNT=10;BYDAY=1FR'],
        [(1997, 'EDT', 'September 5;October 3'),
            (1997, 'EST', 'November 7;December 5'),
            (1998, 'EST', 'January 2;February 6;March 6;April 3'),
            (1998, 'EDT', 'May 1;June 5')], False, None, []),
    ('Monthly on the first Friday until December 24, 1997', '19970905T090000',
        ['FREQ=MONTHLY;UNTIL=19971224T000000Z;BYDAY=1FR'],
        [(1997, 'EDT', 'September 5;October 3'),
            (1997, 'EST', 'November 7;December 5')], False, None, []),
    ('Every other month on the first and last Sunday of the month for 10 occurrences', '19970907T090000',
        ['FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU'],
        [(1997, 'EDT', 'September 7,28'),
            (1997, 'EST', 'November 2,30'),
            (1998, 'EST', 'January 4,25;March 1,29'),
            (1998, 'EDT', 'May 3,31')], False, None, []),
    ('Monthly on the second-to-last Monday of the month for 6 months', '19970922T090000',
        ['FREQ=MONTHLY;COUNT=6;BYDAY=-2MO'],
        [(1997, 'EDT', 'September 22;October 20'),
            (1997, 'EST', 'November 17;December 22'),
            (1998, 'EST', 'January 19;February 16')], False, None, []),
    ('Monthly on the third-to-the-last day of the month, forever', '19970928T090000',
        ['FREQ=MONTHLY;BYMONTHDAY=-3'],
        [(1997, 'EDT', 'September 28'),
            (1997, 'EST', 'October 29;November 28;December 29'),
            (1998, 'EST', 'January 29;February 26')], True, None, []),
    ('Monthly on the 2nd and 15th of the month for 10 occurrences', '19970902T090000',
        ['FREQ=MONTHLY;COUNT=10;BYMONTHDAY=2,15'],
        [(1997, 'EDT', 'September 2,15;October 2,15'),
            (1997, 'EST', 'November 2,15;December 2,15'),
            (1998, 'EST', 'January 2,15')], False, None, []),
    ('Monthly on the first and last day of the month for 10 occurrences', '19970930T090000',
        ['FREQ=MONTHLY;COUNT=10;BYMONTHDAY=1,-1'],
        [(1997, 'EDT', 'September 30;October 1'),
            (1997, 'EST', 'October 31;November 1,30;December 1,31'),
            (1998, 'EST', 'January 1,31;February 1')], False, None, []),
    ('Every 18 months on the 10th thru 15th of the month for 10 occurrences', '19970910T090000',
        ['FREQ=MONTHLY;INTERVAL=18;COUNT=10;BYMONTHDAY=10,11,12,13,14,15'],
        [(1997, 'EDT', 'September 10,11,12,13,14,15'),
            (1999, 'EST', 'March 10,11,12,13')], False, None, []),
    ('Every Tuesday, every other month', '19970902T090000',
        ['FREQ=MONTHLY;INTERVAL=2;BYDAY=TU'],
        [(1997, 'EDT', 'September 2,9,16,23,30'),
            (1997, 'EST', 'November 4,11,18,25'),
            (1998, 'EST', 'January 6,13,20,27;March 3,10,17,24,31')], True, None, []),
    ('Yearly in June and July for 10 occurrences', '19970610T090000',
        ['FREQ=YEARLY;COUNT=10;BYMONTH=6,7'],
        [(year, 'EDT', 'June 10;July 10') for year in range(1997, 2002)], False, None, []),
    ('Every other year on January, February, and March for 10 occurrences', '19970310T090000',
        ['FREQ=YEARLY;INTERVAL=2;COUNT=10;BYMONTH=1,2,3'],
        [(1997, 'EST', 'March 10'),
            (1999, 'EST', 'January 10;February 10;March 10'),
            (2001, 'EST', 'January 10;February 10;March 10'),
            (2003, 'EST', 'January 10;February 10;March 10')], False, None, []),
    ('Every third year on the 1st, 100th, and 200th day for 10 occurrences', '19970101T090000',
        ['FREQ=YEARLY;INTERVAL=3;COUNT=10;BYYEARDAY=1,100,200'],
        [(1997, 'EST', 'January 1'),
            (1997, 'EDT', 'April 10;July 19'),
            (2000, 'EST', 'January 1'),
            (2000, 'EDT', 'April 9;July 18'),
            (2003, 'EST', 'January 1'),
            (2003, 'EDT', 'April 10;July 19'),
            (2006, 'EST', 'January 1')], False, None, []),
    ('Every 20th Monday of the year, forever', '19970519T090000',
        ['FREQ=YEARLY;BYDAY=20MO'],
        [(1997, 'EDT', 'May 19'), (1998, 'EDT', 'May 18'), (1999, 'EDT', 'May 17')], True, None, []),
    ('Monday of week number 20 (where the default start of the week is Monday), forever', '19970512T090000',
        ['FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO'],
        [(1997, 'EDT', 'May 12'), (1998, 'EDT', 'May 11'), (1999, 'EDT', 'May 17')], True, None, []),
    ('Every Thursday in March, forever', '19970313T090000',
        ['FREQ=YEARLY;BYMONTH=3;BYDAY=TH'],
        [(1997, 'EST', 'March 13,20,27'),
            (1998, 'EST', 'March 5,12,19,26'),
            (1999, 'EST', 'March 4,11,18,25')], True, None, []),
    ('Every Thursday, but only during June, July, and August, forever', '19970605T090000',
        ['FREQ=YEARLY;BYDAY=TH;BYMONTH=6,7,8'],
        [(1997, 'EDT', 'June 5,12,19,26;July 3,10,17,24,31;August 7,14,21,28'),
            (1998, 'EDT', 'June 4,11,18,25;July 2,9,16,23,30;August 6,13,20,27'),
            (1999, 'EDT', 'June 3,10,17,24;July 1,8,15,22,29;August 5,12,19,26')], True, None, []),
    ('Every Friday the 13th, forever', '19970902T090000',
        ['FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13'],
        [(1998, 'EST', 'February 13;March 13;November 13'),
            (1999, 'EDT', 'August 13'),
            (2000, 'EDT', 'October 13')], True, {'info': NOT_SYNCHRONIZED_NOTE}, ['19970902T090000']),
    ('The first Saturday that follows the first Sunday of the month, forever', '19970913T090000',
        ['FREQ=MONTHLY;BYDAY=SA;BYMONTHDAY=7,8,9,10,11,12,13'],
        [(1997, 'EDT', 'September 13;October 11'),
            (1997, 'EST', 'November 8;December 13'),
            (1998, 'EST', 'January 10;February 7;March 7'),
            (1998, 'EDT', 'April 11;May 9;June 13')], True, None, []),
    ('Every 4 years, the first Tuesday after a Monday in November, forever (U.S. Presidential Election day)', '19961105T090000',
        ['FREQ=YEARLY;INTERVAL=4;BYMONTH=11;BYDAY=TU;BYMONTHDAY=2,3,4,5,6,7,8'],
        [(1996, 'EST', 'November 5'), (2000, 'EST', 'November 7'), (2004, 'EST', 'November 2')], True, None, []),
    ('The third instance into the month of one of Tuesday, Wednesday, or Thursday, for the next 3 months', '19970904T090000',
        ['FREQ=MONTHLY;COUNT=3;BYDAY=TU,WE,TH;BYSETPOS=3'],
        [(1997, 'EDT', 'September 4;October 7'), (1997, 'EST', 'November 6')], False, None, []),
    ('The second-to-last weekday of the month', '19970929T090000',
        ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-2'],
        [(1997, 'EDT', 'September 29'),
            (1997, 'EST', 'October 30;November 27;December 30'),
            (1998, 'EST', 'January 29;February 26;March 30')], True, None, []),
    ('Every 3 hours from 9:00 AM to 5:00 PM on a specific day', '19970902T090000',
        ['FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T210000Z'],
        [('19970902', 'EDT', '09:00,12:00,15:00')], False,
        {'info': 'Verified erratum 3883 applied: the RFC prints UNTIL=19970902T170000Z, which is 13:00 EDT and gives only 09:00 and 12:00.'}, []),
    ('Every 15 minutes for 6 occurrences', '19970902T090000',
        ['FREQ=MINUTELY;INTERVAL=15;COUNT=6'],
        [('19970902', 'EDT', '09:00,09:15,09:30,09:45,10:00,10:15')], False, None, []),
    ('Every hour and a half for 4 occurrences', '19970902T090000',
        ['FREQ=MINUTELY;INTERVAL=90;COUNT=4'],
        [('19970902', 'EDT', '09:00,10:30,12:00,13:30')], False, None, []),
    ('Every 20 minutes from 9:00 AM to 4:40 PM every day', '19970902T090000',
        ['FREQ=DAILY;BYHOUR=9,10,11,12,13,14,15,16;BYMINUTE=0,20,40',
            'FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10,11,12,13,14,15,16'],
        [('19970902', 'EDT', '09:00-16:40/20'), ('19970903', 'EDT', '09:00-16:40/20')], True, {'info': ELLIPSIS_NOTE}, []),
    ('An example where the days generated makes a difference because of WKST', '19970805T090000',
        ['FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO'],
        [(1997, 'EDT', 'August 5,10,19,24')], False, None, []),
    ('Changing only WKST from MO to SU, yields different results', '19970805T090000',
        ['FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU'],
        [(1997, 'EDT', 'August 5,17,19,31')], False, None, []),
    ('An example where an invalid date (i.e., February 30) is ignored', '20070115T090000',
        ['FREQ=MONTHLY;BYMONTHDAY=15,30;COUNT=5'],
        [(2007, 'EST', 'January 15,30'), (2007, 'EST', 'February 15'), (2007, 'EDT', 'March 15,30')], False, None, []),
]


def expand_numbers(text):
    values = []
    for item in text.split(','):
        match = re.fullmatch(r'(\d+)(?:-(\d+)(?:/(\d+))?)?', item.strip())
        if match is None:
            raise ValueError('bad list item %r' % item)
        first = int(match.group(1))
        last = int(match.group(2) or first)
        step = int(match.group(3) or 1)
        values.extend(range(first, last + 1, step))
    return values


def expand_times(text):
    values = []
    for item in text.split(','):
        match = re.fullmatch(r'(\d\d):(\d\d)(?:-(\d\d):(\d\d)/(\d+))?', item.strip())
        if match is None:
            raise ValueError('bad time item %r' % item)
        first = int(match.group(1)) * 60 + int(match.group(2))
        last = first if match.group(3) is None else int(match.group(3)) * 60 + int(match.group(4))
        step = int(match.group(5) or 1)
        values.extend('%02d%02d00' % divmod(minute, 60) for minute in range(first, last + 1, step))
    return values


def expand_segment(segment, time_of_day):
    year, zone, text = segment
    offset = OFFSETS[zone]
    if isinstance(year, str):
        return ['%sT%s%s' % (year, hhmmss, offset) for hhmmss in expand_times(text)]
    values = []
    for part in text.split(';'):
        month_name, days = part.strip().split(' ', 1)
        month = MONTHS[month_name]
        values.extend('%04d%02d%02dT%s%s' % (year, month, day, time_of_day, offset) for day in expand_numbers(days))
    return values


def section_text(rfc_path):
    text = corpuslib.read_text(rfc_path)
    start = text.index('3.8.5.3.  Recurrence Rule\n')
    end = text.index('3.8.6.  Alarm Component Properties\n', start)
    lines = [
        line for line in text[start:end].split('\n')
        if not line.startswith('Desruisseaux ') and not line.startswith('RFC 5545 ') and '\f' not in line
    ]
    return re.sub(r'\s+', '', '\n'.join(lines))


def check_against_rfc(rfc_path, recurrence_lines):
    compact = section_text(rfc_path)
    missing = []
    for line in recurrence_lines:
        original = ERRATUM_3883[1] if line == ERRATUM_3883[0] else line
        if original not in compact:
            missing.append(line)
    if missing:
        sys.exit('lines not found in RFC 5545 section 3.8.5.3:\n' + '\n'.join(missing))


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: extract_rfc5545.py <rfc5545.txt>')
    cases = []
    all_lines = []
    for title, dtstart, rules, segments, truncated, notes, exdates in EXAMPLES:
        expected = []
        for segment in segments:
            expected.extend(expand_segment(segment, dtstart[9:]))
        for index, rule in enumerate(rules, 1):
            lines = [corpuslib.dtstart_line(dtstart, TZID), 'RRULE:' + rule]
            lines.extend('EXDATE;TZID=%s:%s' % (TZID, value) for value in exdates)
            all_lines.extend(lines)
            description = title if len(rules) == 1 else '%s (RRULE alternative %d of %d)' % (title, index, len(rules))
            cases.append(corpuslib.case(
                corpuslib.numbered(SOURCE, len(cases) + 1),
                description,
                lines,
                expected,
                truncated=truncated,
                notes=notes,
            ))
    check_against_rfc(sys.argv[1], all_lines)
    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'cases.json'),
        corpuslib.cases_document(SOURCE, SOURCE_URL, LICENSE, corpuslib.POLICY_ALWAYS, cases),
    )
    print('%s: %d cases' % (SOURCE, len(cases)))


if __name__ == '__main__':
    main()
