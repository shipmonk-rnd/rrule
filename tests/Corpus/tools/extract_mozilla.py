"""Extract the check_recur() vectors of Mozilla test_recur.js into tests/Corpus/data/mozilla/cases.json.

Usage: python3 -I tests/Corpus/tools/extract_mozilla.py <directory that holds test_recur.js>

The tool reads test_rules() in test_recur.js as text. It does not run JavaScript.
Download the pinned file with:
curl -L -o test_recur.js https://hg.mozilla.org/comm-central/raw-file/<REVISION>/calendar/test/unit/test_recur.js
"""

import hashlib
import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'mozilla'
REVISION = '4208e230566564f0a89ff6035ca9ff7b1b63e63d'
FILE_SHA256 = '6d2f1e3a9a392fce28de45dc42e1f57ab0b27418ef7d676d9766496acf701c8a'
SOURCE_URL = 'https://hg.mozilla.org/comm-central/file/%s/calendar/test/unit/test_recur.js' % REVISION

RANGE_START = '19900101'
RANGE_END = '20200101'
UNSUPPORTED_FREQ = ('SECONDLY', 'MINUTELY')

STRING_RE = re.compile(r'"(?:[^"\\\n]|\\.)*"|\'(?:[^\'\\\n]|\\.)*\'|`(?:[^`\\]|\\.)*`', re.S)
LOOP_RE = re.compile(
    r'const (\w+) = \[\];\s*for \(let (\w+) = (\d+); \2 < (\d+); \2\+\+\) \{\s*'
    r'\1\.push\("(\d*)" \+ \(\2 < 10 \? "0" \+ \2 : \2\) \+ "(\w*)"\);\s*\}'
)
ARRAY_FROM_RE = re.compile(
    r'^Array\.from\(\{ length: (\d+) \}, \(_, (\w+)\) => `(\w*)\$\{String\(\2 \+ (\d+)\)\.padStart\(2, "0"\)\}(\w*)`\)$'
)
JS_ESCAPES = {'n': '\n', 't': '\t', '"': '"', "'": "'", '\\': '\\', '`': '`'}


def blank_comments(text):
    """Replace JS comments with spaces (newlines kept) so offsets stay valid."""
    out = []
    i = 0
    while i < len(text):
        if text[i] in '"\'`':
            match = STRING_RE.match(text, i)
            out.append(match.group(0))
            i = match.end()
        elif text.startswith('//', i):
            end = text.find('\n', i)
            end = len(text) if end < 0 else end
            out.append(' ' * (end - i))
            i = end
        elif text.startswith('/*', i):
            end = text.index('*/', i) + 2
            out.append(re.sub(r'[^\n]', ' ', text[i:end]))
            i = end
        else:
            out.append(text[i])
            i += 1
    return ''.join(out)


def matching(text, start):
    pairs = {'(': ')', '[': ']', '{': '}'}
    stack = []
    i = start
    while i < len(text):
        char = text[i]
        if char in '"\'`':
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


def split_args(text):
    args = []
    depth = 0
    current = []
    i = 0
    while i < len(text):
        char = text[i]
        if char in '"\'`':
            match = STRING_RE.match(text, i)
            current.append(match.group(0))
            i = match.end()
            continue
        if char in '([{':
            depth += 1
        elif char in ')]}':
            depth -= 1
        if char == ',' and depth == 0:
            args.append(''.join(current).strip())
            current = []
        else:
            current.append(char)
        i += 1
    tail = ''.join(current).strip()
    if tail:
        args.append(tail)
    return args


def decode_string(literal):
    return re.sub(r'\\(.)', lambda m: JS_ESCAPES[m.group(1)], literal[1:-1])


def string_concat(expression):
    """Evaluate `"a" + "b" + ...`."""
    parts = []
    position = 0
    expression = expression.strip()
    while True:
        match = STRING_RE.match(expression, position)
        if match is None:
            raise ValueError('not a string concatenation: %r' % expression)
        parts.append(decode_string(match.group(0)))
        rest = re.match(r'\s*(\+)?\s*', expression[match.end():])
        position = match.end() + rest.end()
        if position >= len(expression):
            return ''.join(parts)
        if rest.group(1) is None:
            raise ValueError('not a string concatenation: %r' % expression)


def string_array(expression):
    expression = expression.strip()
    if not (expression.startswith('[') and expression.endswith(']')):
        raise ValueError('not an array: %r' % expression)
    return [string_concat(item) for item in split_args(expression[1:-1])]


def event_text(expression):
    match = re.match(r'^(makeEvent|createEventFromIcalString)\((.*)\)$', expression.strip(), re.S)
    if match is None:
        raise ValueError('not an event: %r' % expression)
    return string_concat(match.group(2))


def rule_part(rule_line, name):
    for part in rule_line.split(':', 1)[1].split(';'):
        key, _, value = part.partition('=')
        if key == name:
            return value
    return None


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    path = os.path.join(sys.argv[1], 'test_recur.js')
    with open(path, 'rb') as handle:
        raw = handle.read()
    if hashlib.sha256(raw).hexdigest() != FILE_SHA256:
        sys.exit('%s is not the file of revision %s' % (path, REVISION))
    text = blank_comments(raw.decode('utf-8'))
    start = text.index('function test_rules() {')
    body_start = text.index('{', start)
    body = text[body_start:matching(text, body_start) + 1]

    generated = {}
    for match in LOOP_RE.finditer(body):
        name, _, low, high, prefix, suffix = match.groups()
        generated[name] = ['%s%s%s' % (prefix, ('0%d' % i) if i < 10 else str(i), suffix) for i in range(int(low), int(high))]

    cases = []
    skipped = {}

    def skip(reason):
        skipped[reason] = skipped.get(reason, 0) + 1

    for call in re.finditer(r'(?<![\w.])check_recur\(', body):
        if body[max(0, call.start() - 9):call.start()].endswith('function '):
            continue
        open_paren = call.end() - 1
        args = split_args(body[open_paren + 1:matching(body, open_paren)])
        event_arg, expected_arg = args[0], args[1]
        if re.fullmatch(r'\w+', event_arg):
            assignments = list(re.finditer(r'(?<![\w.])%s = (makeEvent\()' % re.escape(event_arg), body[:call.start()]))
            assignment = assignments[-1]
            between = body[assignment.start():call.start()]
            if 'modifyException' in between:
                skip('modified occurrence (modifyException), not expressible as RRULE/RDATE/EXDATE')
                continue
            call_start = assignment.start(1)
            ical = event_text(body[call_start:matching(body, call_start + len('makeEvent')) + 1])
        else:
            ical = event_text(event_arg)
        if 'RECURRENCE-ID' in ical:
            skip('overridden occurrence (RECURRENCE-ID), not expressible as RRULE/RDATE/EXDATE')
            continue

        if expected_arg.startswith('['):
            expected = string_array(expected_arg)
        elif expected_arg in generated:
            expected = generated[expected_arg]
        else:
            array_from = ARRAY_FROM_RE.match(expected_arg)
            if array_from is None:
                raise ValueError('unrecognised expectation %r' % expected_arg)
            length, _, prefix, offset, suffix = array_from.groups()
            expected = ['%s%02d%s' % (prefix, i + int(offset), suffix) for i in range(int(length))]

        description = None
        dtstart = None
        other_lines = []
        for line in ical.split('\n'):
            if line.startswith('DESCRIPTION:'):
                description = line[len('DESCRIPTION:'):]
            elif line.startswith('DTSTART'):
                dtstart = line
            elif re.match(r'^(RRULE|EXRULE|RDATE|EXDATE)[;:]', line):
                other_lines.append(line)
            elif line and not re.match(r'^(BEGIN|END|UID|DTEND|DURATION):?', line):
                raise ValueError('unexpected line %r' % line)
        rrules = [line for line in other_lines if line.startswith('RRULE:')]
        if any(rule_part(line, 'FREQ') in UNSUPPORTED_FREQ for line in rrules):
            skip('Mozilla does not expand FREQ=SECONDLY/MINUTELY and expects no instance (product limitation)')
            continue
        if not all(rule_part(line, 'COUNT') or rule_part(line, 'UNTIL') for line in rrules):
            raise ValueError('%s: unbounded rule; the 1990-2020 query window would cut it' % description)
        if any(not (RANGE_START <= value[:8] < RANGE_END) for value in expected):
            raise ValueError('%s: instance outside the 1990-2020 query window' % description)

        notes = {}
        dtstart_value = dtstart.split(':', 1)[1]
        count = rule_part(rrules[0], 'COUNT') if len(rrules) == 1 else None
        has_dates = any(not line.startswith('RRULE:') for line in other_lines)
        if count is not None and not has_dates and expected and expected[0] == dtstart_value and len(expected) == int(count) + 1:
            notes['suspicious'] = (
                'DTSTART does not match the rule; Mozilla returns it in addition to the %s COUNT occurrences '
                '(%d instances). RFC 5545 counts DTSTART as the first occurrence of COUNT.' % (count, len(expected))
            )
        if (
            len(rrules) == 1 and rule_part(rrules[0], 'FREQ') == 'YEARLY' and rule_part(rrules[0], 'BYMONTHDAY')
            and not rule_part(rrules[0], 'BYMONTH') and not rule_part(rrules[0], 'BYDAY')
            and len(expected) > 1 and len({value[4:6] for value in expected}) == 1
        ):
            notes['info'] = (
                'YEARLY with BYMONTHDAY and no BYMONTH: Mozilla keeps the month of DTSTART, as libical and Ical.Net do. '
                'dateutil expands BYMONTHDAY over every month. RFC 5545 does not settle this.'
            )
        if any(line.startswith('EXDATE;VALUE=DATE:') for line in other_lines) and 'VALUE=DATE' not in dtstart:
            if 'info' in notes:
                raise ValueError('two info notes for one case')
            notes['info'] = (
                'An EXDATE of type DATE removes every DATE-TIME occurrence on that date '
                '(in the time zone of the occurrence). This is Mozilla behaviour; RFC 5545 does not define it.'
            )

        index = len(cases) + 1
        cases.append(corpuslib.case(
            corpuslib.numbered(SOURCE, index),
            description,
            [dtstart] + other_lines,
            expected,
            notes=notes or None,
        ))

    document = corpuslib.cases_document(SOURCE, SOURCE_URL, 'MPL-2.0', corpuslib.POLICY_ALWAYS, cases)
    corpuslib.write_json(corpuslib.data_path(SOURCE, 'cases.json'), document)
    print('%s: %d cases' % (SOURCE, len(cases)))
    for reason in sorted(skipped):
        print('  skipped %d: %s' % (skipped[reason], reason))


if __name__ == '__main__':
    main()
