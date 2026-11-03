"""Extract the testRRULE() vectors of ical.js into tests/Corpus/data/ical.js/cases.json.

Usage: python3 -I tests/Corpus/tools/extract_icaljs.py <ical.js clone>

The tool reads test/recur_iterator_test.js as text. It does not run JavaScript.
"""

import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'ical.js'
REPO = 'kewisch/ical.js'
SHA = '7bc16367692451cea30b2751f4ed5532d709d402'
FILE = 'test/recur_iterator_test.js'

STRING_RE = re.compile(r'"(?:[^"\\\n]|\\.)*"|\'(?:[^\'\\\n]|\\.)*\'|`(?:[^`\\]|\\.)*`', re.S)
TIME_RE = re.compile(r'^(\d{4})-(\d\d)-(\d\d)(?:T(\d\d):(\d\d):(\d\d)(Z?))?$')
JS_ESCAPES = {'n': '\n', 't': '\t', '"': '"', "'": "'", '\\': '\\', '`': '`'}
KNOWN_OPTIONS = {'dtStart', 'dates', 'byCount', 'until', 'noInstance'}

# ical.js drops a DTSTART that does not match the rule, except where its iterator does not move the start to the
# first BYSETPOS match. There it returns DTSTART as the first instance and counts it in COUNT.
DTSTART_KEPT = {
    ('FREQ=MONTHLY;BYSETPOS=-1;BYDAY=SU,MO,TU,WE,TH,FR,SA;INTERVAL=1;COUNT=4', '2016-01-01'),
    ('FREQ=MONTHLY;BYSETPOS=2;BYDAY=SU,MO,TU,WE,TH,FR,SA;INTERVAL=1;COUNT=4', '2016-01-01'),
}


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


def split_top(text, separator):
    parts = []
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
        if char == separator and depth == 0:
            parts.append(''.join(current).strip())
            current = []
        else:
            current.append(char)
        i += 1
    tail = ''.join(current).strip()
    if tail:
        parts.append(tail)
    return parts


def literal(expression):
    """Evaluate a JS literal: string (with + concatenation), array, object, boolean."""
    expression = expression.strip()
    if expression.startswith('['):
        return [literal(item) for item in split_top(expression[1:-1], ',')]
    if expression.startswith('{'):
        result = {}
        for item in split_top(expression[1:-1], ','):
            key, value = item.split(':', 1)
            result[key.strip()] = literal(value)
        return result
    if expression in ('true', 'false'):
        return expression == 'true'
    pieces = split_top(expression, '+')
    if all(STRING_RE.fullmatch(piece) for piece in pieces):
        return ''.join(re.sub(r'\\(.)', lambda m: JS_ESCAPES[m.group(1)], piece[1:-1]) for piece in pieces)
    raise ValueError('unsupported literal %r' % expression)


def ical_value(value):
    match = TIME_RE.match(value)
    if match is None:
        raise ValueError('unsupported time %r' % value)
    year, month, day, hour, minute, second, utc = match.groups()
    if hour is None:
        return year + month + day
    return '%s%s%sT%s%s%s%s' % (year, month, day, hour, minute, second, utc)


def comment_before(original, position):
    """Return the // comment block directly above the line that holds position."""
    lines = original[:position].split('\n')[:-1]
    block = []
    while lines and lines[-1].strip().startswith('//'):
        block.insert(0, lines.pop().strip()[2:].strip())
    text = ' '.join(line for line in block if line)
    return text.strip("', ")


def rule_parts(rule):
    return dict(part.split('=', 1) for part in rule.split(';') if part)


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    original = corpuslib.read_text(os.path.join(clone, FILE))
    text = blank_comments(original)
    cases = []
    kept = set()
    for call in re.finditer(r'(?<![\w.])testRRULE\(', text):
        if text[max(0, call.start() - 9):call.start()] == 'function ':
            continue
        open_paren = call.end() - 1
        rule_arg, options_arg = split_top(text[open_paren + 1:matching(text, open_paren)], ',')
        if (rule_arg, options_arg) == ('ruleString', 'options'):
            continue
        rule = literal(rule_arg)
        options = literal(options_arg)
        unknown = set(options) - KNOWN_OPTIONS
        if unknown:
            raise ValueError('%s: unsupported options %r' % (rule, sorted(unknown)))
        parts = rule_parts(rule)
        finite = 'COUNT' in parts or 'UNTIL' in parts
        if bool(options.get('byCount') or options.get('until')) != finite:
            raise ValueError('%s: finiteness flags disagree with the rule' % rule)
        dates = options.get('dates', [])
        dtstart = ical_value(options.get('dtStart') or dates[0])
        expected = [ical_value(value) for value in dates]
        notes = None
        if options.get('noInstance'):
            if expected:
                raise ValueError('%s: noInstance with dates' % rule)
            notes = {'info': 'ical.js returns no instance for this rule.' + ('' if finite else ' The rule is unbounded, so an engine needs a search limit to end.')}
        if (
            parts.get('FREQ') == 'YEARLY' and 'BYMONTHDAY' in parts and 'BYMONTH' not in parts
            and 'BYWEEKNO' not in parts and 'BYYEARDAY' not in parts and 'BYDAY' not in parts
            and len(expected) > 1 and len({value[4:6] for value in expected}) == 1
        ):
            notes = {'info': (
                'YEARLY with BYMONTHDAY and no BYMONTH: ical.js keeps the month of DTSTART, as libical and Ical.Net do. '
                'dateutil expands BYMONTHDAY over every month. RFC 5545 does not settle this.'
            )}
        policy = None
        if (rule, options.get('dtStart')) in DTSTART_KEPT:
            kept.add((rule, options.get('dtStart')))
            if not expected or expected[0] != dtstart:
                raise ValueError('%s: DTSTART is no longer the first instance' % rule)
            policy = corpuslib.POLICY_ALWAYS
            notes = {'info': 'ical.js returns this DTSTART although it does not match the rule, and counts it in COUNT.'}
        description = comment_before(original, call.start()) or rule
        cases.append(corpuslib.case(
            corpuslib.numbered(SOURCE, len(cases) + 1),
            description,
            [corpuslib.dtstart_line(dtstart), 'RRULE:' + rule],
            expected,
            truncated=not finite and bool(expected),
            notes=notes,
            dt_start_policy=policy,
        ))
    if kept != DTSTART_KEPT:
        raise ValueError('DTSTART_KEPT entries not found: %r' % sorted(DTSTART_KEPT - kept))
    document = corpuslib.cases_document(
        SOURCE,
        corpuslib.github_url(REPO, SHA, FILE),
        'MPL-2.0',
        corpuslib.POLICY_ONLY_IF_MATCHING,
        cases,
    )
    corpuslib.write_json(corpuslib.data_path(SOURCE, 'cases.json'), document)
    print('%s: %d cases' % (SOURCE, len(cases)))


if __name__ == '__main__':
    main()
