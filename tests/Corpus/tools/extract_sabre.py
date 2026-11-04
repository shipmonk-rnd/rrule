"""Extract sabre/vobject RRuleIteratorTest vectors into tests/Corpus/data/sabre/cases.json.

Usage: python3 -I tests/Corpus/tools/extract_sabre.py <sabre-io/vobject clone>

The tool reads tests/VObject/Recur/RRuleIteratorTest.php as text. It does not run PHP.
"""

import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'sabre'
REPO = 'sabre-io/vobject'
SHA = '5e2f338811916c03927398bb24e527989de63194'
FILE = 'tests/VObject/Recur/RRuleIteratorTest.php'
EXCEPTION_NAMESPACE = 'Sabre\\VObject\\'

STRING_RE = re.compile(r"'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\"", re.S)
METHOD_RE = re.compile(
    r"(?:#\[DataProvider\('(?P<provider>\w+)'\)\]\s*)?public (?:static )?function (?P<name>\w+)\((?P<params>[^)]*)\)\s*:\s*\??\w+\s*\{"
)
TIME_RE = re.compile(r'^(\d{4})-(\d\d)-(\d\d)(?: (\d\d):(\d\d):(\d\d))?$')
PARSE_PARAMS = ['rule', 'start', 'expected', 'fastForward', 'tz', 'runTillTheEnd']

# Expectations that contradict RFC 5545 (with errata); the runner should not hold the engine to them.
SUSPICIOUS = {
    'testIgnoredStuff': (
        'sabre ignores BYSECOND, BYMINUTE, BYYEARDAY and BYWEEKNO for FREQ=DAILY. RFC 5545 does not allow '
        'BYYEARDAY or BYWEEKNO with FREQ=DAILY, and BYSECOND/BYMINUTE would change the times.'
    ),
    'testWeeklyByDayByHourOnDstTransition': (
        'sabre drops the 02:00 instance on 2023-03-26 (inside the DST gap). RFC 5545 section 3.3.5 '
        '(erratum 4271) moves a nonexistent local time forward, which gives 03:00.'
    ),
    'testYearlyByYearDayMaxNegative': (
        'sabre returns December 31 for BYYEARDAY=-366 in years with 365 days. In RFC 5545 day -366 exists '
        'only in leap years (January 1).'
    ),
    'testNegativeValidByWeekNo': (
        'sabre puts BYWEEKNO=-20 one week too early (2012-08-07; ISO week 33 of 2012, the 20th week from the end, '
        'starts on 2012-08-13).'
    ),
}
INFO = {
    'testUntilBeginHasTimezone': 'UNTIL is a local time while DTSTART has a TZID; RFC 5545 requires a UTC UNTIL here.',
    'testUntilBeforeDtStart': 'UNTIL is before DTSTART; sabre still returns DTSTART.',
    'testNeverEnding': 'The rule has no instance (BYSETPOS=2 of a one-day set); sabre returns DTSTART only and ends.',
    'testDailyBySetPosLoop': 'The rule has no instance (every 7 days from a Tuesday, BYDAY=MO); sabre ends the iteration.',
    'testYearlyBySetPosLoop': 'The rule has no instance (BYSETPOS=3 of a one-day set); sabre ends the iteration.',
    'testValidByWeekNoByDayDefault': (
        'BYWEEKNO without BYDAY: sabre keeps the weekday of DTSTART (as libical does); dateutil returns every day '
        'of the week. RFC 5545 does not say which.'
    ),
    'test2HourlyOnDstTransition': (
        'HOURLY counts wall-clock hours; an instance in the DST gap moves forward by the gap length. '
        'RFC 5545 does not say whether HOURLY counts wall-clock or elapsed hours.'
    ),
    'testHourlyOnDstTransition': (
        'HOURLY counts wall-clock hours; an instance in the DST gap moves forward by the gap length. '
        'RFC 5545 does not say whether HOURLY counts wall-clock or elapsed hours.'
    ),
}


def blank_comments(text):
    out = []
    i = 0
    while i < len(text):
        char = text[i]
        if char in '\'"':
            match = STRING_RE.match(text, i)
            out.append(match.group(0))
            i = match.end()
        elif text.startswith('//', i) or (char == '#' and not text.startswith('#[', i)):
            end = text.find('\n', i)
            end = len(text) if end < 0 else end
            out.append(' ' * (end - i))
            i = end
        elif text.startswith('/*', i):
            end = text.index('*/', i) + 2
            out.append(re.sub(r'[^\n]', ' ', text[i:end]))
            i = end
        else:
            out.append(char)
            i += 1
    return ''.join(out)


def matching(text, start):
    pairs = {'(': ')', '[': ']', '{': '}'}
    stack = []
    i = start
    while i < len(text):
        char = text[i]
        if char in '\'"':
            i = STRING_RE.match(text, i).end()
            continue
        if char in pairs:
            stack.append(pairs[char])
        elif char in ')]}':
            if stack.pop() != char:
                raise ValueError('unbalanced brackets at %d' % i)
            if not stack:
                return i
        i += 1
    raise ValueError('unbalanced brackets')


def split_top(text, separator=','):
    parts = []
    depth = 0
    current = []
    i = 0
    while i < len(text):
        char = text[i]
        if char in '\'"':
            match = STRING_RE.match(text, i)
            current.append(match.group(0))
            i = match.end()
            continue
        if char in '([{':
            depth += 1
        elif char in ')]}':
            depth -= 1
        if text.startswith(separator, i) and depth == 0:
            parts.append(''.join(current).strip())
            current = []
            i += len(separator)
            continue
        current.append(char)
        i += 1
    tail = ''.join(current).strip()
    if tail:
        parts.append(tail)
    return parts


def php_value(expression, variables):
    expression = expression.strip()
    if STRING_RE.fullmatch(expression):
        if expression[0] == '"':
            raise ValueError('double-quoted strings are not supported: %r' % expression)
        return re.sub(r"\\(['\\])", r'\1', expression[1:-1])
    if expression.startswith('['):
        items = split_top(expression[1:-1])
        if items and all(len(split_top(item, '=>')) == 2 for item in items):
            return {php_value(split_top(item, '=>')[0], variables): php_value(split_top(item, '=>')[1], variables) for item in items}
        return [php_value(item, variables) for item in items]
    if expression in ('null', 'true', 'false'):
        return {'null': None, 'true': True, 'false': False}[expression]
    if expression.startswith('$'):
        return variables[expression[1:]]
    raise ValueError('unsupported PHP expression %r' % expression)


def provider_rows(text, name, params):
    method = next(m for m in METHOD_RE.finditer(text) if m.group('name') == name)
    body = text[method.end() - 1:matching(text, method.end() - 1) + 1]
    rows = []
    yields = list(re.finditer(r"yield\s+('(?:[^'\\]|\\.)*')\s*=>\s*\[", body))
    if yields:
        for match in yields:
            open_bracket = match.end() - 1
            rows.append((php_value(match.group(1), {}), php_value(body[open_bracket:matching(body, open_bracket) + 1], {})))
    else:
        returned = re.search(r'return\s*\[', body)
        open_bracket = returned.end() - 1
        for index, row in enumerate(php_value(body[open_bracket:matching(body, open_bracket) + 1], {}), 1):
            rows.append(('#%d' % index, row))
    bound = []
    for key, row in rows:
        if isinstance(row, list):
            row = dict(zip(params, row))
        if set(row) != set(params):
            raise ValueError('%s %s: row keys %r do not match %r' % (name, key, sorted(row), params))
        bound.append((key, row))
    return bound


def ical_value(value, utc):
    match = TIME_RE.match(value)
    if match is None:
        raise ValueError('unsupported time %r' % value)
    year, month, day, hour, minute, second = match.groups()
    if hour is None:
        hour = minute = second = '00'
    return '%s%s%sT%s%s%s%s' % (year, month, day, hour, minute, second, 'Z' if utc else '')


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    text = blank_comments(corpuslib.read_text(os.path.join(clone, FILE)))
    extracted = []
    skipped = {}
    for method in METHOD_RE.finditer(text):
        name = method.group('name')
        if not name.startswith('test'):
            continue
        body = text[method.end() - 1:matching(text, method.end() - 1) + 1]
        call = re.search(r'\$this->parse\(', body)
        if call is None:
            reason = 'does not call parse() (tests the iterator API, not an expansion)'
            skipped[reason] = skipped.get(reason, 0) + 1
            continue
        exception = re.search(r'\$this->expectException\((\w+)::class\)', body)
        open_paren = call.end() - 1
        arg_texts = split_top(body[open_paren + 1:matching(body, open_paren)])
        params = [p.split('$')[1] for p in method.group('params').split(',') if '$' in p]
        if method.group('provider'):
            runs = [('%s: %s' % (name, key), variables) for key, variables in provider_rows(text, method.group('provider'), params)]
        else:
            runs = [(name, {})]
        for label, variables in runs:
            args = dict(zip(PARSE_PARAMS, [php_value(arg, variables) for arg in arg_texts]))
            extracted.append((name, label, args, exception.group(1) if exception else None))

    cases = []
    for name, label, args, exception in extracted:
        tz = args.get('tz', 'UTC')
        utc = tz == 'UTC'
        rule = args['rule']
        parts = dict(part.split('=', 1) for part in rule.split(';') if '=' in part)
        infinite = 'COUNT' not in parts and 'UNTIL' not in parts
        expected = [ical_value(value, utc) for value in args['expected']]
        start_at = None
        notes = {}
        if args.get('fastForward') and exception is None:
            if not utc:
                raise ValueError('%s: fastForward with a non-UTC zone' % label)
            start_at = ical_value(args['fastForward'], True)
            notes['info'] = (
                'sabre fastForward: expected lists only the instances at or after startAt; '
                'the source does not list the earlier instances.'
            )
        if name in INFO:
            notes['info'] = (notes['info'] + ' ' if 'info' in notes else '') + INFO[name]
        if name in SUSPICIOUS:
            notes['suspicious'] = SUSPICIOUS[name]
        truncated = infinite and not args.get('runTillTheEnd') and bool(expected) and exception is None
        dtstart = corpuslib.dtstart_line(ical_value(args['start'], utc), None if utc else tz)
        cases.append(corpuslib.case(
            corpuslib.numbered(SOURCE, len(cases) + 1),
            label,
            [dtstart, 'RRULE:' + rule],
            [] if exception else expected,
            start_at=start_at,
            expected_error=(EXCEPTION_NAMESPACE + exception) if exception else None,
            truncated=truncated,
            notes=notes or None,
        ))
    document = corpuslib.cases_document(
        SOURCE,
        corpuslib.github_url(REPO, SHA, FILE),
        'BSD-3-Clause',
        corpuslib.POLICY_ALWAYS,
        cases,
    )
    corpuslib.write_json(corpuslib.data_path(SOURCE, 'cases.json'), document)
    print('%s: %d cases' % (SOURCE, len(cases)))
    for reason in sorted(skipped):
        print('  skipped %d: %s' % (skipped[reason], reason))


if __name__ == '__main__':
    main()
