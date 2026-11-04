"""Recompute every RFC 5545 example in data/rfc5545-examples/cases.json by brute force.

Usage: python3 -I tests/Corpus/tools/verify_rfc5545.py

Each rule below is a hand-written predicate over local wall-clock times, written from the
RRULE text and not from the RFC prose. The script walks every candidate day (or minute) from
DTSTART, applies COUNT, UNTIL and EXDATE, and compares the result and the UTC offsets
(from zoneinfo America/New_York) with the hand-encoded prose in cases.json.
"""

import calendar
import datetime
import json
import os
import sys
import zoneinfo

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

ZONE = zoneinfo.ZoneInfo('America/New_York')
UTC = datetime.timezone.utc
MO, TU, WE, TH, FR, SA, SU = range(7)


def days_from_start(d, start):
    return (d.date() - start.date()).days


def months_from_start(d, start):
    return (d.year - start.year) * 12 + d.month - start.month


def week_index(d, start, wkst):
    def week_start(value):
        return value.date() - datetime.timedelta(days=(value.weekday() - wkst) % 7)
    return (week_start(d) - week_start(start)).days // 7


def last_day(d):
    return calendar.monthrange(d.year, d.month)[1]


def nth_in_month(d):
    return (d.day - 1) // 7 + 1


def nth_from_end_of_month(d):
    return -((last_day(d) - d.day) // 7 + 1)


def nth_in_year(d):
    return (d.timetuple().tm_yday - 1) // 7 + 1


def set_position(d, weekdays):
    candidates = [day for day in range(1, last_day(d) + 1) if datetime.date(d.year, d.month, day).weekday() in weekdays]
    if d.weekday() not in weekdays:
        return None
    index = candidates.index(d.day)
    return index + 1, index - len(candidates)


def minutes_from_start(d, start):
    return int((d - start).total_seconds()) // 60


# id -> (expected RRULE text, step, predicate(d, start))
RULES = {
    1: ('FREQ=DAILY;COUNT=10', 'day', lambda d, s: True),
    2: ('FREQ=DAILY;UNTIL=19971224T000000Z', 'day', lambda d, s: True),
    3: ('FREQ=DAILY;INTERVAL=2', 'day', lambda d, s: days_from_start(d, s) % 2 == 0),
    4: ('FREQ=DAILY;INTERVAL=10;COUNT=5', 'day', lambda d, s: days_from_start(d, s) % 10 == 0),
    5: ('FREQ=YEARLY;UNTIL=20000131T140000Z;BYMONTH=1;BYDAY=SU,MO,TU,WE,TH,FR,SA', 'day', lambda d, s: d.month == 1),
    6: ('FREQ=DAILY;UNTIL=20000131T140000Z;BYMONTH=1', 'day', lambda d, s: d.month == 1),
    7: ('FREQ=WEEKLY;COUNT=10', 'day', lambda d, s: d.weekday() == s.weekday()),
    8: ('FREQ=WEEKLY;UNTIL=19971224T000000Z', 'day', lambda d, s: d.weekday() == s.weekday()),
    9: ('FREQ=WEEKLY;INTERVAL=2;WKST=SU', 'day', lambda d, s: d.weekday() == s.weekday() and week_index(d, s, SU) % 2 == 0),
    10: ('FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH', 'day', lambda d, s: d.weekday() in (TU, TH)),
    11: ('FREQ=WEEKLY;COUNT=10;WKST=SU;BYDAY=TU,TH', 'day', lambda d, s: d.weekday() in (TU, TH)),
    12: ('FREQ=WEEKLY;INTERVAL=2;UNTIL=19971224T000000Z;WKST=SU;BYDAY=MO,WE,FR', 'day',
            lambda d, s: d.weekday() in (MO, WE, FR) and week_index(d, s, SU) % 2 == 0),
    13: ('FREQ=WEEKLY;INTERVAL=2;COUNT=8;WKST=SU;BYDAY=TU,TH', 'day',
            lambda d, s: d.weekday() in (TU, TH) and week_index(d, s, SU) % 2 == 0),
    14: ('FREQ=MONTHLY;COUNT=10;BYDAY=1FR', 'day', lambda d, s: d.weekday() == FR and nth_in_month(d) == 1),
    15: ('FREQ=MONTHLY;UNTIL=19971224T000000Z;BYDAY=1FR', 'day', lambda d, s: d.weekday() == FR and nth_in_month(d) == 1),
    16: ('FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU', 'day',
            lambda d, s: months_from_start(d, s) % 2 == 0 and d.weekday() == SU and (nth_in_month(d) == 1 or nth_from_end_of_month(d) == -1)),
    17: ('FREQ=MONTHLY;COUNT=6;BYDAY=-2MO', 'day', lambda d, s: d.weekday() == MO and nth_from_end_of_month(d) == -2),
    18: ('FREQ=MONTHLY;BYMONTHDAY=-3', 'day', lambda d, s: d.day == last_day(d) - 2),
    19: ('FREQ=MONTHLY;COUNT=10;BYMONTHDAY=2,15', 'day', lambda d, s: d.day in (2, 15)),
    20: ('FREQ=MONTHLY;COUNT=10;BYMONTHDAY=1,-1', 'day', lambda d, s: d.day in (1, last_day(d))),
    21: ('FREQ=MONTHLY;INTERVAL=18;COUNT=10;BYMONTHDAY=10,11,12,13,14,15', 'day',
            lambda d, s: months_from_start(d, s) % 18 == 0 and 10 <= d.day <= 15),
    22: ('FREQ=MONTHLY;INTERVAL=2;BYDAY=TU', 'day', lambda d, s: months_from_start(d, s) % 2 == 0 and d.weekday() == TU),
    23: ('FREQ=YEARLY;COUNT=10;BYMONTH=6,7', 'day', lambda d, s: d.month in (6, 7) and d.day == s.day),
    24: ('FREQ=YEARLY;INTERVAL=2;COUNT=10;BYMONTH=1,2,3', 'day',
            lambda d, s: (d.year - s.year) % 2 == 0 and d.month in (1, 2, 3) and d.day == s.day),
    25: ('FREQ=YEARLY;INTERVAL=3;COUNT=10;BYYEARDAY=1,100,200', 'day',
            lambda d, s: (d.year - s.year) % 3 == 0 and d.timetuple().tm_yday in (1, 100, 200)),
    26: ('FREQ=YEARLY;BYDAY=20MO', 'day', lambda d, s: d.weekday() == MO and nth_in_year(d) == 20),
    27: ('FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO', 'day', lambda d, s: d.weekday() == MO and d.isocalendar()[1] == 20),
    28: ('FREQ=YEARLY;BYMONTH=3;BYDAY=TH', 'day', lambda d, s: d.month == 3 and d.weekday() == TH),
    29: ('FREQ=YEARLY;BYDAY=TH;BYMONTH=6,7,8', 'day', lambda d, s: d.month in (6, 7, 8) and d.weekday() == TH),
    30: ('FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13', 'day', lambda d, s: d.day == 13 and d.weekday() == FR),
    31: ('FREQ=MONTHLY;BYDAY=SA;BYMONTHDAY=7,8,9,10,11,12,13', 'day', lambda d, s: 7 <= d.day <= 13 and d.weekday() == SA),
    32: ('FREQ=YEARLY;INTERVAL=4;BYMONTH=11;BYDAY=TU;BYMONTHDAY=2,3,4,5,6,7,8', 'day',
            lambda d, s: (d.year - s.year) % 4 == 0 and d.month == 11 and d.weekday() == TU and 2 <= d.day <= 8),
    33: ('FREQ=MONTHLY;COUNT=3;BYDAY=TU,WE,TH;BYSETPOS=3', 'day',
            lambda d, s: (set_position(d, (TU, WE, TH)) or (0, 0))[0] == 3),
    34: ('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-2', 'day',
            lambda d, s: (set_position(d, (MO, TU, WE, TH, FR)) or (0, 0))[1] == -2),
    35: ('FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T210000Z', 'minute', lambda d, s: minutes_from_start(d, s) % 180 == 0),
    36: ('FREQ=MINUTELY;INTERVAL=15;COUNT=6', 'minute', lambda d, s: minutes_from_start(d, s) % 15 == 0),
    37: ('FREQ=MINUTELY;INTERVAL=90;COUNT=4', 'minute', lambda d, s: minutes_from_start(d, s) % 90 == 0),
    38: ('FREQ=DAILY;BYHOUR=9,10,11,12,13,14,15,16;BYMINUTE=0,20,40', 'minute', lambda d, s: 9 <= d.hour <= 16 and d.minute in (0, 20, 40)),
    39: ('FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10,11,12,13,14,15,16', 'minute',
            lambda d, s: minutes_from_start(d, s) % 20 == 0 and 9 <= d.hour <= 16),
    40: ('FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO', 'day',
            lambda d, s: d.weekday() in (TU, SU) and week_index(d, s, MO) % 2 == 0),
    41: ('FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU', 'day',
            lambda d, s: d.weekday() in (TU, SU) and week_index(d, s, SU) % 2 == 0),
    42: ('FREQ=MONTHLY;BYMONTHDAY=15,30;COUNT=5', 'day', lambda d, s: d.day in (15, 30)),
}


def parse_local(value):
    return datetime.datetime.strptime(value[:15], '%Y%m%dT%H%M%S')


def offset_text(local):
    offset = local.replace(tzinfo=ZONE).utcoffset()
    minutes = int(offset.total_seconds()) // 60
    sign = '-' if minutes < 0 else '+'
    return '%s%02d%02d' % (sign, abs(minutes) // 60, abs(minutes) % 60)


def rule_parts(rule):
    return dict(part.split('=', 1) for part in rule.split(';'))


def brute_force(start, rule, step, predicate, exdates, horizon):
    parts = rule_parts(rule)
    count = int(parts['COUNT']) if 'COUNT' in parts else None
    until = None
    if 'UNTIL' in parts:
        until = datetime.datetime.strptime(parts['UNTIL'], '%Y%m%dT%H%M%SZ').replace(tzinfo=UTC)
    delta = datetime.timedelta(days=1) if step == 'day' else datetime.timedelta(minutes=1)
    result = []
    current = start
    produced = 0
    while current <= horizon:
        if until is not None and current.replace(tzinfo=ZONE).astimezone(UTC) > until:
            break
        if current == start or predicate(current, start):
            produced += 1
            if current not in exdates:
                result.append(current)
            if count is not None and produced == count:
                break
        current += delta
    return result


def main():
    with open(corpuslib.data_path('rfc5545-examples', 'cases.json'), encoding='utf-8') as handle:
        document = json.load(handle)
    failures = 0
    unsynchronized = []
    for item in document['cases']:
        number = int(item['id'].rsplit('-', 1)[1])
        rule_text, step, predicate = RULES[number]
        lines = item['recurrence'].split('\n')
        start = parse_local(lines[0].split(':', 1)[1])
        rules = [line[6:] for line in lines if line.startswith('RRULE:')]
        exdates = {parse_local(line.split(':', 1)[1]) for line in lines if line.startswith('EXDATE')}
        if rules != [rule_text]:
            print('%s: RRULE %r does not match the predicate table (%r)' % (item['id'], rules, rule_text))
            failures += 1
            continue
        if not predicate(start, start):
            unsynchronized.append(item['id'])
        expected = item['expected']
        if item['truncated']:
            horizon = parse_local(expected[-1])
        else:
            horizon = parse_local(expected[-1]) + datetime.timedelta(days=800)
        computed = brute_force(start, rule_text, step, predicate, exdates, horizon)
        computed_text = [value.strftime('%Y%m%dT%H%M%S') + offset_text(value) for value in computed]
        if computed_text != expected:
            failures += 1
            print('%s: MISMATCH %s' % (item['id'], item['description']))
            print('  expected-only: %s' % sorted(set(expected) - set(computed_text)))
            print('  computed-only: %s' % sorted(set(computed_text) - set(expected))[:10])
    print('cases: %d, mismatches: %d, DTSTART not matching the rule: %s' % (len(document['cases']), failures, ', '.join(unsynchronized) or 'none'))
    if failures:
        sys.exit(1)


if __name__ == '__main__':
    main()
