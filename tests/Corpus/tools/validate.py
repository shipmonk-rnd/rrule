"""Validate every tests/Corpus/data/*/cases.json and invalid.json file.

Usage: python3 -I tests/Corpus/tools/validate.py
Exit code 1 and one line per problem when a file is not valid.
"""

import glob
import json
import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

POLICIES = {corpuslib.POLICY_ALWAYS, corpuslib.POLICY_ONLY_IF_MATCHING}
CASES_TOP_KEYS = ['source', 'sourceUrl', 'license', 'dtStartPolicy', 'cases']
INVALID_TOP_KEYS = ['source', 'sourceUrl', 'license', 'cases']
CASE_KEYS = ['id', 'description', 'recurrence', 'startAt', 'expected', 'expectedError', 'truncated', 'notes']
CASE_OPTIONAL_KEYS = ['dtStartPolicy']

DTSTART_RE = re.compile(r'^DTSTART(?:;VALUE=DATE:(?P<date>\d{8})|;TZID=(?P<tzid>[^;:]+):(?P<zoned>\d{8}T\d{6})|:(?P<plain>\d{8}T\d{6}Z?))$')
RULE_RE = re.compile(r'^(?:RRULE|EXRULE):\S+$')
DATES_RE = re.compile(r'^(?:RDATE|EXDATE)(?:;[A-Z-]+=[^;:]+)*:\S+$')

VALUE_RES = {
    'date': re.compile(r'^\d{8}$'),
    'floating': re.compile(r'^\d{8}T\d{6}$'),
    'utc': re.compile(r'^\d{8}T\d{6}Z$'),
    'zoned': re.compile(r'^\d{8}T\d{6}(?:[+-]\d{4})?$'),
}


def dtstart_kind(line):
    match = DTSTART_RE.match(line)
    if match is None:
        return None
    if match.group('date'):
        return 'date'
    if match.group('zoned'):
        return 'zoned'
    return 'utc' if match.group('plain').endswith('Z') else 'floating'


def check_notes(notes, problems, where):
    if notes is None or isinstance(notes, str):
        if isinstance(notes, str) and notes == '':
            problems.append('%s: empty notes string' % where)
        return
    if not isinstance(notes, dict) or not notes:
        problems.append('%s: notes must be null, a string or a non-empty object' % where)
        return
    for key, value in notes.items():
        if not isinstance(value, str) or value == '':
            problems.append('%s: notes.%s must be a non-empty string' % (where, key))


def check_recurrence(recurrence, problems, where):
    if not isinstance(recurrence, str) or recurrence == '':
        problems.append('%s: recurrence must be a non-empty string' % where)
        return None
    lines = recurrence.split('\n')
    kind = None
    dtstarts = 0
    rrules = 0
    for line in lines:
        if line.startswith('DTSTART'):
            dtstarts += 1
            kind = dtstart_kind(line)
            if kind is None:
                problems.append('%s: bad DTSTART line %r' % (where, line))
        elif line.startswith('RRULE:'):
            rrules += 1
            if not RULE_RE.match(line):
                problems.append('%s: bad RRULE line %r' % (where, line))
        elif line.startswith('EXRULE:'):
            if not RULE_RE.match(line):
                problems.append('%s: bad EXRULE line %r' % (where, line))
        elif line.startswith('RDATE') or line.startswith('EXDATE'):
            if not DATES_RE.match(line):
                problems.append('%s: bad RDATE/EXDATE line %r' % (where, line))
        else:
            problems.append('%s: unexpected line %r' % (where, line))
    if dtstarts != 1:
        problems.append('%s: %d DTSTART lines, want exactly 1' % (where, dtstarts))
    if rrules < 1:
        problems.append('%s: no RRULE line' % where)
    return kind


def check_case(item, problems, where):
    keys = list(item.keys())
    expected_keys = CASE_KEYS + [k for k in CASE_OPTIONAL_KEYS if k in item]
    if keys != expected_keys:
        problems.append('%s: keys %r, want %r' % (where, keys, expected_keys))
        return
    if not isinstance(item['description'], str) or item['description'].strip() == '':
        problems.append('%s: empty description' % where)
    kind = check_recurrence(item['recurrence'], problems, where)
    expected = item['expected']
    if not isinstance(expected, list) or not all(isinstance(v, str) for v in expected):
        problems.append('%s: expected must be a list of strings' % where)
        return
    if kind is not None:
        value_re = VALUE_RES[kind]
        for value in expected:
            if not value_re.match(value):
                problems.append('%s: expected value %r does not match the %s DTSTART form' % (where, value, kind))
        if kind == 'zoned' and expected:
            with_offset = [len(v) > 15 for v in expected]
            if any(with_offset) and not all(with_offset):
                problems.append('%s: mixes values with and without UTC offset' % where)
        start_at = item['startAt']
        if start_at is not None:
            start_re = VALUE_RES['floating'] if kind == 'zoned' else value_re
            if not isinstance(start_at, str) or not start_re.match(start_at):
                problems.append('%s: startAt %r does not match the %s DTSTART form' % (where, start_at, kind))
    for previous, current in zip(expected, expected[1:]):
        if not previous < current:
            problems.append('%s: expected not strictly increasing at %r, %r' % (where, previous, current))
            break
    error = item['expectedError']
    if error is not None:
        if not isinstance(error, str) or error == '':
            problems.append('%s: expectedError must be null or a non-empty string' % where)
        if expected:
            problems.append('%s: expectedError with non-empty expected' % where)
        if item['truncated']:
            problems.append('%s: expectedError with truncated' % where)
    if not isinstance(item['truncated'], bool):
        problems.append('%s: truncated must be a boolean' % where)
    elif item['truncated'] and not expected:
        problems.append('%s: truncated with empty expected' % where)
    check_notes(item['notes'], problems, where)
    if 'dtStartPolicy' in item and item['dtStartPolicy'] not in POLICIES:
        problems.append('%s: bad dtStartPolicy %r' % (where, item['dtStartPolicy']))


def check_invalid_case(item, problems, where):
    keys = list(item.keys())
    if keys not in (['id', 'description', 'rule', 'expectedError', 'notes'], ['id', 'description', 'recurrence', 'expectedError', 'notes']):
        problems.append('%s: keys %r' % (where, keys))
        return
    if not isinstance(item['description'], str) or item['description'].strip() == '':
        problems.append('%s: empty description' % where)
    if 'rule' in item:
        if not isinstance(item['rule'], str) or '\n' in item['rule']:
            problems.append('%s: rule must be a single-line string' % where)
    else:
        recurrence = item['recurrence']
        if not isinstance(recurrence, str) or recurrence == '':
            problems.append('%s: recurrence must be a non-empty string' % where)
        else:
            for line in recurrence.split('\n'):
                if line.strip() == '' or line != line.strip():
                    problems.append('%s: empty line or surrounding whitespace in %r' % (where, line))
    if not isinstance(item['expectedError'], str) or item['expectedError'] == '':
        problems.append('%s: expectedError must be a non-empty string' % where)
    check_notes(item['notes'], problems, where)


def check_file(path, kind, seen_ids, problems):
    with open(path, 'r', encoding='utf-8', newline='') as handle:
        text = handle.read()
    try:
        document = json.loads(text)
    except ValueError as error:
        problems.append('%s: not JSON: %s' % (path, error))
        return 0
    if text != json.dumps(document, indent=2, ensure_ascii=False) + '\n':
        problems.append('%s: not in canonical form (2-space indent, UTF-8, trailing newline)' % path)
    top_keys = CASES_TOP_KEYS if kind == 'cases' else INVALID_TOP_KEYS
    if list(document.keys()) != top_keys:
        problems.append('%s: top-level keys %r, want %r' % (path, list(document.keys()), top_keys))
        return 0
    source = document['source']
    if source != os.path.basename(os.path.dirname(path)):
        problems.append('%s: source %r does not match the directory name' % (path, source))
    for key in ('sourceUrl', 'license'):
        if not isinstance(document[key], str) or document[key] == '':
            problems.append('%s: %s must be a non-empty string' % (path, key))
    if kind == 'cases' and document['dtStartPolicy'] not in POLICIES:
        problems.append('%s: bad dtStartPolicy %r' % (path, document['dtStartPolicy']))
    if not isinstance(document['cases'], list) or not document['cases']:
        problems.append('%s: cases must be a non-empty list' % path)
        return 0
    for index, item in enumerate(document['cases']):
        if not isinstance(item, dict) or not isinstance(item.get('id'), str):
            problems.append('%s: case #%d has no id' % (path, index))
            continue
        where = '%s: %s' % (os.path.relpath(path, corpuslib.DATA_DIR), item['id'])
        if item['id'] in seen_ids:
            problems.append('%s: duplicate id' % where)
        seen_ids.add(item['id'])
        if not item['id'].startswith(source + '-'):
            problems.append('%s: id does not start with %r' % (where, source + '-'))
        if kind == 'cases':
            check_case(item, problems, where)
        else:
            check_invalid_case(item, problems, where)
    return len(document['cases'])


def main():
    problems = []
    seen_ids = set()
    totals = []
    for source_dir in sorted(glob.glob(os.path.join(corpuslib.DATA_DIR, '*'))):
        if not os.path.isdir(source_dir):
            continue
        found = False
        if not os.path.exists(os.path.join(source_dir, 'NOTICE')):
            problems.append('%s: missing NOTICE' % source_dir)
        for kind in ('cases', 'invalid'):
            path = os.path.join(source_dir, kind + '.json')
            if os.path.exists(path):
                found = True
                totals.append('%s/%s.json: %d' % (os.path.basename(source_dir), kind, check_file(path, kind, seen_ids, problems)))
        if not found:
            problems.append('%s: neither cases.json nor invalid.json' % source_dir)
    for line in totals:
        print(line)
    for problem in problems:
        print('ERROR ' + problem)
    if problems:
        sys.exit(1)
    print('OK: %d ids' % len(seen_ids))


if __name__ == '__main__':
    main()
