"""Expand recurrences with python-dateutil.

Run with "python3 -I". The input on stdin is a JSON list of cases:

    [{"recurrence": "DTSTART;TZID=Europe/Prague:20260101T090000\\nRRULE:FREQ=DAILY", "limit": 50}, ...]

The recurrence is parsed by dateutil.rrule.rrulestr(forceset=True). TZID values
are zoneinfo.ZoneInfo zones. The output is a JSON list with one item per case:
a list of the first "limit" occurrences, or an error string.

The occurrence form follows the DTSTART form: YYYYMMDD for a DATE start,
YYYYMMDDTHHMMSS for floating and zoned starts (local wall time),
YYYYMMDDTHHMMSSZ for a UTC start. dateutil has no DATE values, so a DATE
start gives YYYYMMDDTHHMMSS for an occurrence that is not at midnight.
"""

import itertools
import json
import re
import signal
import sys
import warnings
from zoneinfo import ZoneInfo

from dateutil.rrule import rrulestr

TIMEOUT_SECONDS = 10
DTSTART = re.compile(r"^DTSTART(?P<params>(;[^:]*)?):(?P<value>\d{8}(T\d{6}Z?)?)$", re.MULTILINE)


class CaseTimeout(Exception):
    pass


def on_alarm(signum, frame):
    raise CaseTimeout(f"no result after {TIMEOUT_SECONDS} s")


def format_occurrence(occurrence, form):
    if form == "date" and occurrence.hour == occurrence.minute == occurrence.second == 0:
        return occurrence.strftime("%Y%m%d")
    if form == "utc":
        return occurrence.strftime("%Y%m%dT%H%M%SZ")
    return occurrence.strftime("%Y%m%dT%H%M%S")


def start_form(recurrence):
    match = DTSTART.search(recurrence)
    if match is None:
        raise ValueError("no DTSTART line")
    if "VALUE=DATE" in match.group("params").split(";") or len(match.group("value")) == 8:
        return "date"
    if match.group("value").endswith("Z"):
        return "utc"
    return "local"


def expand(case):
    recurrence = case["recurrence"]
    form = start_form(recurrence)
    signal.setitimer(signal.ITIMER_REAL, TIMEOUT_SECONDS)
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            rule_set = rrulestr(recurrence, forceset=True, tzids=ZoneInfo)
            occurrences = list(itertools.islice(rule_set, case["limit"]))
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
    return [format_occurrence(occurrence, form) for occurrence in occurrences]


def main():
    signal.signal(signal.SIGALRM, on_alarm)
    result = []
    for case in json.load(sys.stdin):
        try:
            result.append(expand(case))
        except Exception as error:
            result.append(f"{type(error).__name__}: {error}")
    json.dump(result, sys.stdout)


main()
