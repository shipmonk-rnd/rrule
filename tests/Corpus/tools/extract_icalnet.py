"""Generate data/ical.net/cases.json and invalid.json from Ical.Net RecurrenceTestCases.txt.

Usage: python3 -I tests/Corpus/tools/extract_icalnet.py <ical.net clone>

The block format is the libical one. Ical.Net adds:
- EXCEPTION: <.NET exception type>: the test expects this exception (it is not an EXDATE).
- EXCEPTION-STEP: Construction (default), GetOccurrenceInvocation or Enumeration.
- DURATION: the event duration. It does not change the start times.
The runner (Ical.Net.Tests/RecurrenceTests.cs, ExecuteRecurrenceTestCase) reads DTSTART and
START-AT as UTC and evaluates in UTC, so a floating DTSTART gives the same instances.
"""

import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'ical.net'
REPO = 'ical-org/ical.net'
SHA = 'b7cd9063576825fd52b26839d1e16f7187d2e023'
PATH = 'Ical.Net.Tests/Calendars/Recurrence/RecurrenceTestCases.txt'
KEYS = ('RRULE', 'DTSTART', 'START-AT', 'INSTANCES', 'EXCEPTION', 'EXCEPTION-STEP', 'DURATION')

OUT_OF_RANGE = 'Ical.Net.Evaluation.EvaluationOutOfRangeException'
SKIP_OUT_OF_RANGE = 'year-9999 limit: Ical.Net throws EvaluationOutOfRangeException when an instance or the search passes year 9999'
SKIP_DURATION = 'DURATION decides the result (the event end passes year 9999)'

RFC_ERROR_NOTES = {
    'FREQ=DAILY;BYDAY=1MO': 'RFC 5545 section 3.3.10: BYDAY with a numeric value MUST NOT be used unless FREQ is MONTHLY or YEARLY.',
    'FREQ=MONTHLY;BYWEEKNO=10': 'RFC 5545 section 3.3.10: BYWEEKNO MUST NOT be used unless FREQ is YEARLY.',
    'FREQ=MONTHLY;BYYEARDAY=14;COUNT=3': 'RFC 5545 section 3.3.10: BYYEARDAY MUST NOT be used with FREQ=DAILY, WEEKLY or MONTHLY.',
    'FREQ=WEEKLY;BYMONTHDAY=20;COUNT=3': 'RFC 5545 section 3.3.10: BYMONTHDAY MUST NOT be used with FREQ=WEEKLY.',
}
LENIENT_NOTES = {
    'BYDAY=0': 'Ical.Net ignores the BYDAY value with offset 0. RFC 5545 ordwk is 1 to 53, so a strict parser rejects the rule.',
    'BYWEEKNO_NUMERIC_BYDAY': (
        'RFC 5545 section 3.3.10: BYDAY MUST NOT have a numeric value with FREQ=YEARLY and BYWEEKNO. '
        'Ical.Net is lenient and applies the offset within the week.'
    ),
}


def parse_blocks(text):
    blocks = []
    comments = []
    current = {}
    start_line = 0
    for line_no, line in enumerate(text.split('\n'), 1):
        if line.strip() == '':
            if current:
                blocks.append((start_line, list(comments), dict(current)))
            current.clear()
            comments.clear()
            continue
        if line.startswith('#'):
            comments.append(line[1:].strip())
            continue
        match = re.fullmatch(r'([A-Z-]+):(.*)', line)
        if match is None or match.group(1) not in KEYS:
            sys.exit('unexpected line %d: %r' % (line_no, line))
        if match.group(1) in current:
            sys.exit('duplicate %s at line %d' % (match.group(1), line_no))
        if not current:
            start_line = line_no
        current[match.group(1)] = match.group(2)
    if current:
        blocks.append((start_line, list(comments), dict(current)))
    return blocks


def start_at_value(start_at, dtstart, instances):
    if start_at is None:
        return None, None
    notes = []
    if len(start_at) == 8 and len(dtstart) > 8:
        notes.append('START-AT is the DATE %s in the source; the runner reads it as midnight.' % start_at)
        start_at += 'T000000'
    if dtstart < start_at <= instances[0]:
        notes.append('The source lists only the instances from START-AT on.')
    return start_at, ' '.join(notes) or None


def lenient_note(rule):
    if re.search(r'BYDAY=([^;]*,)?[+-]?0[A-Z]{2}', rule):
        return LENIENT_NOTES['BYDAY=0']
    if 'BYWEEKNO=' in rule and re.search(r'BYDAY=([^;]*,)?[+-]?\d+[A-Z]{2}', rule) and 'FREQ=YEARLY' in rule:
        return LENIENT_NOTES['BYWEEKNO_NUMERIC_BYDAY']
    return None


def build_notes(info, suspicious):
    notes = {}
    if suspicious:
        notes['suspicious'] = suspicious
    info = [text for text in info if text]
    if info:
        notes['info'] = ' '.join(info)
    return notes or None


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: extract_icalnet.py <ical.net clone>')
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    cases = []
    invalid = []
    skipped = {}
    for start_line, comments, block in parse_blocks(corpuslib.read_text(os.path.join(clone, PATH))):
        rule = block['RRULE']
        description = '%s [line %d]' % (' '.join(c for c in comments if c) or 'RRULE:' + rule, start_line)
        exception = block.get('EXCEPTION')
        step = block.get('EXCEPTION-STEP', 'Construction' if exception else None)
        if exception is not None and step == 'Construction':
            if 'DTSTART' in block:
                sys.exit('construction error with DTSTART at line %d' % start_line)
            invalid.append(corpuslib.invalid_case(
                corpuslib.numbered(SOURCE + '-invalid', len(invalid) + 1),
                description,
                exception,
                rule=rule,
                notes={'info': 'Ical.Net throws when it parses the RRULE value (EXCEPTION-STEP Construction).'},
            ))
            continue
        if exception is not None and 'DURATION' in block:
            skipped.setdefault(SKIP_DURATION, []).append(start_line)
            continue
        if exception == OUT_OF_RANGE:
            skipped.setdefault(SKIP_OUT_OF_RANGE, []).append(start_line)
            continue
        if exception is not None and rule not in RFC_ERROR_NOTES:
            sys.exit('unhandled exception case at line %d' % start_line)
        if exception is not None and step != 'Enumeration':
            sys.exit('unexpected EXCEPTION-STEP at line %d' % start_line)
        start_at, start_note = start_at_value(block.get('START-AT'), block['DTSTART'], block.get('INSTANCES', '').split(','))
        duration_note = None
        if 'DURATION' in block:
            duration_note = 'The source also has DURATION:%s; it does not change the start times.' % block['DURATION']
        expected = []
        if exception is None:
            expected = block['INSTANCES'].split(',')
        cases.append(corpuslib.case(
            corpuslib.numbered(SOURCE, len(cases) + 1),
            description,
            [corpuslib.dtstart_line(block['DTSTART']), 'RRULE:' + rule],
            expected,
            start_at=start_at,
            expected_error=exception,
            notes=build_notes([RFC_ERROR_NOTES.get(rule) if exception else None, start_note, duration_note], lenient_note(rule)),
        ))
    source_url = corpuslib.github_url(REPO, SHA, PATH)
    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'cases.json'),
        corpuslib.cases_document(SOURCE, source_url, 'MIT', corpuslib.POLICY_ONLY_IF_MATCHING, cases),
    )
    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'invalid.json'),
        corpuslib.invalid_document(SOURCE, source_url, 'MIT', invalid),
    )
    print('%s: %d cases, %d invalid' % (SOURCE, len(cases), len(invalid)))
    for reason, lines in sorted(skipped.items()):
        print('skipped %d (%s): lines %s' % (len(lines), reason, ', '.join(map(str, lines))))


if __name__ == '__main__':
    main()
