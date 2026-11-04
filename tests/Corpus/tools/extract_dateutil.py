"""Extract python-dateutil rrule test vectors into tests/Corpus/data/dateutil/.

Usage: python3 -I tests/Corpus/tools/extract_dateutil.py <dateutil clone> [--verbose]

The tool parses tests/test_rrule.py with the `ast` module. It never imports or runs it.
It converts `list(rrule(...)) == [datetime(...), ...]`, `list(rrulestr("...")) == [...]`
and `rruleset()` assertions with literal arguments to RFC 5545 text, and the
`ValueError` tests to invalid.json. Skip counts go to stderr.
"""

import ast
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.dont_write_bytecode = True

import corpuslib  # noqa: E402

REPO = 'dateutil/dateutil'
SHA = 'a78cbd81d5d03b5f126eb36edb92bca78972c804'
TEST_FILE = 'tests/test_rrule.py'
SOURCE = 'dateutil'
LICENSE = 'Apache-2.0 AND BSD-3-Clause'

FREQS = ['YEARLY', 'MONTHLY', 'WEEKLY', 'DAILY', 'HOURLY', 'MINUTELY', 'SECONDLY']
RULE_PARTS = {
    'count': 'COUNT',
    'until': 'UNTIL',
    'interval': 'INTERVAL',
    'wkst': 'WKST',
    'bysetpos': 'BYSETPOS',
    'bymonth': 'BYMONTH',
    'bymonthday': 'BYMONTHDAY',
    'byyearday': 'BYYEARDAY',
    'byweekno': 'BYWEEKNO',
    'byweekday': 'BYDAY',
    'byhour': 'BYHOUR',
    'byminute': 'BYMINUTE',
    'bysecond': 'BYSECOND',
}
RFC_PARTS = {
    'FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYDAY', 'BYMONTHDAY',
    'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS', 'WKST',
}

DATE_DTSTART_NOTE = 'dateutil returns midnight datetimes for a DATE DTSTART; the tool writes them as DATE values.'


class Skip(Exception):
    pass


class DateTime:
    def __init__(self, fields, tz, is_date):
        self.fields = fields
        self.tz = tz
        self.is_date = is_date

    def value(self):
        year, month, day, hour, minute, second, microsecond = self.fields
        if microsecond:
            raise Skip('microseconds cannot be written in RFC 5545')
        if self.is_date:
            return '%04d%02d%02d' % (year, month, day)
        return '%04d%02d%02dT%02d%02d%02d' % (year, month, day, hour, minute, second)

    def is_midnight(self):
        return self.fields[3:] == (0, 0, 0, 0)


class Weekday:
    def __init__(self, index, n):
        self.index = index
        self.n = n

    def text(self):
        name = corpuslib.WEEKDAYS[self.index]
        return name if self.n is None else '%d%s' % (self.n, name)


class Tz:
    def __init__(self, name):
        self.name = name


class Rule:
    def __init__(self, freq, kwargs):
        self.freq = freq
        self.kwargs = kwargs


class RuleStr:
    def __init__(self, text, kwargs):
        self.text = text
        self.kwargs = kwargs


class RuleSet:
    def __init__(self, kwargs):
        self.kwargs = kwargs
        self.items = []


class Unknown:
    def __init__(self, reason):
        self.reason = reason


class LocalFunction:
    def __init__(self, node):
        self.node = node


class Listed:
    """The result of list(<rule>)."""

    def __init__(self, target):
        self.target = target


def evaluate(node, env):
    if isinstance(node, ast.Constant):
        return node.value
    if isinstance(node, ast.UnaryOp) and isinstance(node.op, (ast.USub, ast.UAdd)):
        value = evaluate(node.operand, env)
        if not isinstance(value, int):
            raise Skip('non-literal argument')
        return -value if isinstance(node.op, ast.USub) else value
    if isinstance(node, (ast.Tuple, ast.List, ast.Set)):
        return [evaluate(item, env) for item in node.elts]
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Add):
        left = evaluate(node.left, env)
        right = evaluate(node.right, env)
        if isinstance(left, str) and isinstance(right, str):
            return left + right
        raise Skip('non-literal argument')
    if isinstance(node, ast.Name):
        if node.id in FREQS:
            return ('freq', node.id)
        if node.id in corpuslib.WEEKDAYS:
            return Weekday(corpuslib.WEEKDAYS.index(node.id), None)
        if node.id in env:
            value = env[node.id]
            if isinstance(value, Unknown):
                raise Skip(value.reason)
            return value
        raise Skip('non-literal argument')
    if isinstance(node, ast.Attribute):
        if isinstance(node.value, ast.Name) and node.value.id == 'tz' and node.attr == 'UTC':
            return Tz('UTC')
        if node.attr == '_cache':
            raise Skip('internal cache inspection')
        raise Skip('non-literal argument')
    if isinstance(node, ast.Subscript):
        target = evaluate(node.value, env)
        if isinstance(target, (Rule, RuleStr, RuleSet)):
            index = node.slice
            if isinstance(index, ast.Slice) and index.lower is None and index.upper is None and index.step is None:
                return Listed(target)
            raise Skip('index or slice query, not the full list')
        raise Skip('non-literal argument')
    if isinstance(node, ast.Call):
        return evaluate_call(node, env)
    raise Skip('non-literal argument')


def keyword_items(node, env):
    items = []
    for keyword in node.keywords:
        if keyword.arg is None:
            value = keyword.value
            if isinstance(value, ast.Call) and isinstance(value.func, ast.Name) and value.func.id == 'dict' and not value.args:
                items.extend((k.arg, evaluate(k.value, env)) for k in value.keywords)
                continue
            raise Skip('non-literal argument')
        items.append((keyword.arg, evaluate(keyword.value, env)))
    return items


def evaluate_call(node, env):
    func = node.func
    if isinstance(func, ast.Name):
        name = func.id
        if name in ('datetime', 'date'):
            args = [evaluate(arg, env) for arg in node.args]
            kwargs = dict(keyword_items(node, env))
            if not all(isinstance(arg, int) for arg in args) or set(kwargs) - {'tzinfo'}:
                raise Skip('non-literal argument')
            fields = tuple(args + [0] * (7 - len(args)))
            tz = kwargs.get('tzinfo')
            if tz is not None and not isinstance(tz, Tz):
                raise Skip('non-literal argument')
            return DateTime(fields, tz, name == 'date')
        if name in corpuslib.WEEKDAYS:
            if len(node.args) != 1 or node.keywords:
                raise Skip('non-literal argument')
            return Weekday(corpuslib.WEEKDAYS.index(name), evaluate(node.args[0], env))
        if name == 'rrule':
            items = keyword_items(node, env)
            if node.args:
                freq = evaluate(node.args[0], env)
            else:
                found = [value for key, value in items if key == 'freq']
                items = [(key, value) for key, value in items if key != 'freq']
                freq = found[0] if found else None
            if not (isinstance(freq, tuple) and freq[0] == 'freq'):
                raise Skip('non-literal argument')
            return Rule(freq[1], items)
        if name == 'rrulestr':
            if len(node.args) != 1:
                raise Skip('non-literal argument')
            text = evaluate(node.args[0], env)
            if not isinstance(text, str):
                raise Skip('non-literal argument')
            options = [keyword for keyword in node.keywords if keyword.arg == 'tzids']
            if options:
                return RuleStr(text, [('tzids', None)])
            return RuleStr(text, keyword_items(node, env))
        if name == 'rruleset':
            return RuleSet(keyword_items(node, env))
        if name == 'list':
            if len(node.args) != 1:
                raise Skip('non-literal argument')
            value = evaluate(node.args[0], env)
            if isinstance(value, (Rule, RuleStr, RuleSet)):
                return Listed(value)
            if isinstance(value, (list, Listed)):
                return value
            raise Skip('non-literal argument')
        raise Skip('non-literal argument')
    if isinstance(func, ast.Attribute):
        owner = func.value
        if isinstance(owner, ast.Name) and owner.id == 'tz' and func.attr == 'gettz':
            zone = evaluate(node.args[0], env)
            if not isinstance(zone, str):
                raise Skip('non-literal argument')
            return Tz(zone)
        if isinstance(owner, ast.Constant) and isinstance(owner.value, str) and func.attr == 'join':
            parts = evaluate(node.args[0], env)
            if not all(isinstance(part, str) for part in parts):
                raise Skip('non-literal argument')
            return owner.value.join(parts)
        if func.attr == 'replace':
            target = evaluate(owner, env)
            if not isinstance(target, Rule):
                raise Skip('non-literal argument')
            replaced = dict(keyword_items(node, env))
            kwargs = [(key, replaced.pop(key, value)) for key, value in target.kwargs]
            kwargs.extend(replaced.items())
            return Rule(target.freq, kwargs)
        if func.attr in ('between', 'after', 'before', 'xafter', 'count'):
            raise Skip('%s() query, not the full list' % func.attr)
    raise Skip('non-literal argument')


def format_int_list(value):
    values = value if isinstance(value, list) else [value]
    if not values or not all(isinstance(v, int) and not isinstance(v, bool) for v in values):
        raise Skip('non-literal argument')
    return ','.join(str(v) for v in values)


def format_weekdays(value):
    values = value if isinstance(value, list) else [value]
    parts = []
    for item in values:
        if isinstance(item, Weekday):
            parts.append(item.text())
        elif isinstance(item, int) and 0 <= item <= 6:
            parts.append(corpuslib.WEEKDAYS[item])
        else:
            raise Skip('non-literal argument')
    return ','.join(parts)


def rule_value(rule):
    """Return (dtstart DateTime, RRULE value) for an rrule() call."""
    dtstart = None
    parts = ['FREQ=' + rule.freq]
    for key, value in rule.kwargs:
        if key == 'dtstart':
            dtstart = value
            continue
        if key == 'byeaster':
            raise Skip('byeaster (not RFC 5545)')
        if key == 'cache':
            raise Skip('cache=True (repeats a non-cache test)')
        if key not in RULE_PARTS:
            raise Skip('unsupported rrule() argument %s' % key)
        part = RULE_PARTS[key]
        if key == 'until':
            if not isinstance(value, DateTime):
                raise Skip('non-literal argument')
            if value.tz is not None:
                raise Skip('timezone-aware rrule() arguments')
            text = value.value()
        elif key == 'wkst':
            if isinstance(value, Weekday) and value.n is None:
                text = value.text()
            elif isinstance(value, int) and 0 <= value <= 6:
                text = corpuslib.WEEKDAYS[value]
            else:
                raise Skip('non-literal argument')
        elif key == 'byweekday':
            text = format_weekdays(value)
        elif key in ('count', 'interval'):
            if not isinstance(value, int):
                raise Skip('non-literal argument')
            text = str(value)
        else:
            text = format_int_list(value)
        parts.append('%s=%s' % (part, text))
    if not isinstance(dtstart, DateTime):
        raise Skip('no literal dtstart (dateutil uses the current time)')
    if dtstart.tz is not None:
        raise Skip('timezone-aware rrule() arguments')
    return dtstart, ';'.join(parts)


def dtstart_line(dtstart):
    return corpuslib.dtstart_line(dtstart.value())


def recurrence_from_rule(rule):
    dtstart, value = rule_value(rule)
    return [dtstart_line(dtstart), 'RRULE:' + value], dtstart_kind(dtstart), None, []


def dtstart_kind(dtstart):
    return 'date' if dtstart.is_date else 'floating'


def recurrence_from_set(rule_set):
    if rule_set.kwargs:
        raise Skip('cache=True (repeats a non-cache test)')
    start = None
    rule_lines = []
    date_lines = []
    for method, value in rule_set.items:
        if method in ('rrule', 'exrule'):
            if not isinstance(value, Rule):
                raise Skip('non-literal argument')
            dtstart, text = rule_value(value)
            if start is None:
                start = dtstart
            elif start.value() != dtstart.value() or start.is_date != dtstart.is_date:
                raise Skip('rruleset members with different dtstart values')
            rule_lines.append('%s:%s' % (method.upper(), text))
        else:
            if not isinstance(value, DateTime) or value.tz is not None:
                raise Skip('non-literal argument')
            date_lines.append('%s:%s' % (method.upper(), value.value()))
    if not any(line.startswith('RRULE:') for line in rule_lines):
        raise Skip('set without RRULE (RDATE/EXRULE only)')
    for line in date_lines:
        if dtstart_kind(start) == 'date' and 'T' in line:
            raise Skip('non-literal argument')
    return [dtstart_line(start)] + rule_lines + date_lines, dtstart_kind(start), None, []


DTSTART_TEXT_RE = re.compile(r'^DTSTART(?P<params>(?:;[A-Z-]+=[^;:]+)*):(?P<value>\S+)$')


def recurrence_from_text(rule_str):
    for key, _ in rule_str.kwargs:
        if key == 'tzids':
            raise Skip('rrulestr() with a custom tzids mapping or callable')
        if key in ('dtstart', 'unfold', 'forceset', 'compatible', 'ignoretz', 'tzinfos'):
            raise Skip('rrulestr() parser option (%s=); the rule duplicates testStr' % key)
        raise Skip('non-literal argument')
    lines = [line.strip() for line in rule_str.text.split('\n')]
    lines = [line for line in lines if line != '']
    notes = []
    result = []
    kind = None
    tzid = None
    for line in lines:
        name = re.split(r'[;:]', line, maxsplit=1)[0]
        if name != name.upper() or ' ' in line:
            raise Skip('rrulestr() lenient syntax (lower case, spaces, bare rule value)')
        if name in ('RRULE', 'EXRULE') and line != line.upper():
            raise Skip('rrulestr() lenient syntax (lower case, spaces, bare rule value)')
        if line.startswith('DTSTART'):
            match = DTSTART_TEXT_RE.match(line)
            if match is None:
                raise Skip('rrulestr() lenient syntax (lower case, spaces, bare rule value)')
            params = [p for p in match.group('params').split(';') if p]
            value = match.group('value')
            kept = []
            for param in params:
                if param == 'VALUE=DATE-TIME':
                    notes.append('The source writes DTSTART;VALUE=DATE-TIME; the tool drops the default VALUE parameter.')
                elif param == 'VALUE=DATE':
                    kind = 'date'
                elif param.startswith('TZID='):
                    tzid = param[5:]
                    kept.append(param)
                else:
                    raise Skip('rrulestr() lenient syntax (lower case, spaces, bare rule value)')
            if kind == 'date':
                if kept or not re.fullmatch(r'\d{8}', value):
                    raise Skip('non-literal argument')
                result.append(corpuslib.dtstart_line(value))
            else:
                result.append(corpuslib.dtstart_line(value, tzid))
                kind = 'zoned' if tzid is not None else ('utc' if value.endswith('Z') else 'floating')
            continue
        if name not in ('RRULE', 'EXRULE', 'RDATE', 'EXDATE'):
            raise Skip('rrulestr() lenient syntax (lower case, spaces, bare rule value)')
        if name in ('RRULE', 'EXRULE'):
            if not line.startswith(name + ':'):
                raise Skip('RRULE with a parameter (validate.py allows no RRULE parameters)')
            for part in line[len(name) + 1:].split(';'):
                if part.split('=', 1)[0] not in RFC_PARTS:
                    raise Skip('non-RFC rule part (%s)' % part.split('=', 1)[0])
        result.append(line)
    if kind is None:
        raise Skip('rrulestr() parser option (dtstart=); the rule duplicates testStr')
    if not any(line.startswith('RRULE:') for line in result):
        raise Skip('set without RRULE (RDATE/EXRULE only)')
    return result, kind, tzid, notes


def expected_values(values, kind, tzid, notes):
    if not isinstance(values, list):
        raise Skip('non-literal argument')
    result = []
    for value in values:
        if not isinstance(value, DateTime):
            raise Skip('non-literal argument')
        if kind == 'date':
            if not value.is_midnight():
                raise Skip('DATE dtstart with time-of-day occurrences')
            if not value.is_date and DATE_DTSTART_NOTE not in notes:
                notes.append(DATE_DTSTART_NOTE)
            result.append(value.value()[:8])
            continue
        if value.is_date:
            raise Skip('non-literal argument')
        if kind == 'zoned':
            if value.tz is None or value.tz.name != tzid:
                raise Skip('expected values in another time zone')
            result.append(value.value())
        elif kind == 'utc':
            if value.tz is None or value.tz.name != 'UTC':
                raise Skip('expected values in another time zone')
            result.append(value.value() + 'Z')
        else:
            if value.tz is not None:
                raise Skip('timezone-aware rrule() arguments')
            result.append(value.value())
    return result


Candidate = collections.namedtuple('Candidate', 'recurrence expected notes')
InvalidCandidate = collections.namedtuple('InvalidCandidate', 'recurrence rule error notes')


def assertion_case(actual_node, expected_node, env):
    is_list_call = isinstance(actual_node, ast.Call) and isinstance(actual_node.func, ast.Name) and actual_node.func.id == 'list'
    is_query = isinstance(actual_node, ast.Call) and isinstance(actual_node.func, ast.Attribute)
    if not (is_list_call or is_query or isinstance(actual_node, (ast.Subscript, ast.Attribute))):
        raise Skip('not an occurrence-list comparison (str(), in, isinstance(), weekday API)')
    actual = evaluate(actual_node, env)
    if not isinstance(actual, Listed):
        raise Skip('not an occurrence-list comparison (str(), in, isinstance(), weekday API)')
    target = actual.target
    if isinstance(target, Rule):
        lines, kind, tzid, notes = recurrence_from_rule(target)
    elif isinstance(target, RuleStr):
        lines, kind, tzid, notes = recurrence_from_text(target)
    else:
        lines, kind, tzid, notes = recurrence_from_set(target)
    notes = list(notes)
    expected = expected_values(evaluate(expected_node, env), kind, tzid, notes)
    if expected != sorted(set(expected)):
        raise Skip('expected list not in ascending order')
    return Candidate(lines, expected, notes)


def invalid_from_call(call_target, error, env):
    """Build an invalid case from the expression that the source expects to raise."""
    if isinstance(call_target, LocalFunction):
        body = [s for s in call_target.node.body if not isinstance(s, ast.Expr) or not isinstance(s.value, ast.Constant)]
        if len(body) != 1 or not isinstance(body[0], ast.Expr):
            raise Skip('non-literal argument')
        call_target = evaluate(body[0].value, env)
    if isinstance(call_target, Listed):
        call_target = call_target.target
    if isinstance(call_target, Rule):
        lines, _, _, _ = recurrence_from_rule(call_target)
        return InvalidCandidate(lines, None, error, [])
    if isinstance(call_target, RuleStr):
        return invalid_from_text(call_target.text, error)
    raise Skip('raises test on the weekday API, not on a rule')


def invalid_from_text(text, error):
    lines = [line.strip() for line in text.split('\n') if line.strip() != '']
    if len(lines) == 2 and lines[0].startswith('DTSTART:') and not lines[1].startswith(('RRULE', 'EXRULE', 'RDATE', 'EXDATE', 'DTSTART')):
        return InvalidCandidate(None, lines[1], error, ['The source passes the bare rule value after the line %s.' % lines[0]])
    return InvalidCandidate(lines, None, error, [])


def call_name(node):
    if isinstance(node, ast.Call):
        func = node.func
        if isinstance(func, ast.Attribute) and isinstance(func.value, ast.Name):
            return func.value.id + '.' + func.attr
        if isinstance(func, ast.Name):
            return func.id
    return None


class Extractor:
    def __init__(self):
        self.cases = []
        self.invalid = []
        self.skips = collections.Counter()
        self.skip_examples = collections.defaultdict(list)

    def skip(self, reason, name):
        self.skips[reason] += 1
        self.skip_examples[reason].append(name)

    def run(self, tree):
        for node in tree.body:
            if isinstance(node, ast.ClassDef):
                for item in node.body:
                    if isinstance(item, ast.FunctionDef) and item.name.startswith('test'):
                        self.function(item)
            elif isinstance(node, ast.FunctionDef) and node.name.startswith('test'):
                self.skip('module-level pytest test (pickle round trip, cache, frozen clock)', node.name)

    def function(self, node):
        env = {}
        found = []
        invalid = []
        reasons = []
        description = node.name
        docstring = ast.get_docstring(node)
        if docstring:
            description += ': ' + ' '.join(docstring.split('\n\n')[0].split())

        def attempt(make, target):
            try:
                target.append(make())
            except Skip as error:
                reasons.append(str(error))

        for statement in node.body:
            if isinstance(statement, ast.FunctionDef):
                env[statement.name] = LocalFunction(statement)
            elif isinstance(statement, ast.Assign):
                if len(statement.targets) == 1 and isinstance(statement.targets[0], ast.Name):
                    try:
                        env[statement.targets[0].id] = evaluate(statement.value, env)
                    except Skip as error:
                        env[statement.targets[0].id] = Unknown(str(error))
            elif isinstance(statement, ast.Assert):
                test = statement.test
                if isinstance(test, ast.Compare) and len(test.ops) == 1 and isinstance(test.ops[0], ast.Eq):
                    attempt(lambda: assertion_case(test.left, test.comparators[0], env), found)
            elif isinstance(statement, ast.Expr) and isinstance(statement.value, ast.Call):
                call = statement.value
                name = call_name(call)
                if name == 'self.assertEqual' and len(call.args) == 2:
                    attempt(lambda: assertion_case(call.args[0], call.args[1], env), found)
                elif name == 'self.assertRaises' and call.args:
                    attempt(lambda: self.raises_call(call, env), invalid)
                elif isinstance(call.func, ast.Attribute) and isinstance(call.func.value, ast.Name) \
                        and isinstance(env.get(call.func.value.id), RuleSet) \
                        and call.func.attr in ('rrule', 'rdate', 'exrule', 'exdate') and len(call.args) == 1:
                    try:
                        value = evaluate(call.args[0], env)
                    except Skip as error:
                        value = Unknown(str(error))
                    env[call.func.value.id].items.append((call.func.attr, value))
            elif isinstance(statement, ast.With):
                attempt(lambda: self.raises_with(statement, env), invalid)
            elif isinstance(statement, ast.For):
                reasons.append('loop in the test body (cache, count() or repr test)')
        for index, candidate in enumerate(found):
            text = description if len(found) == 1 else '%s (case %d of %d)' % (description, index + 1, len(found))
            self.cases.append((text, candidate))
        for index, candidate in enumerate(invalid):
            text = description if len(invalid) == 1 else '%s (case %d of %d)' % (description, index + 1, len(invalid))
            self.invalid.append((text, candidate))
        if found or invalid:
            for reason in reasons:
                self.skip('extra assertion in an extracted test: ' + reason, node.name)
        elif reasons:
            self.skip(reasons[0], node.name)
        elif node.name.startswith('testToStr'):
            self.skip('str() round trip only, no expected occurrences', node.name)
        else:
            self.skip('no occurrence-list assertion (API, str() or weekday test)', node.name)

    def raises_call(self, call, env):
        error_node = call.args[0]
        error = error_node.id if isinstance(error_node, ast.Name) else None
        if error != 'ValueError':
            raise Skip('expects a test-local exception class')
        if len(call.args) == 1:
            raise Skip('non-literal argument')
        target_node = call.args[1]
        if isinstance(target_node, ast.Name) and target_node.id in ('rrule', 'rrulestr'):
            fake = ast.Call(func=target_node, args=call.args[2:], keywords=call.keywords)
            return invalid_from_call(evaluate(fake, env), error, env)
        return invalid_from_call(evaluate(target_node, env), error, env)

    def raises_with(self, statement, env):
        if len(statement.items) != 1:
            raise Skip('non-literal argument')
        context = statement.items[0].context_expr
        name = call_name(context)
        if name not in ('self.assertRaises', 'pytest.raises', 'pytest.warns') or len(context.args) != 1:
            raise Skip('non-literal argument')
        error_node = context.args[0]
        error = error_node.id if isinstance(error_node, ast.Name) else None
        if error not in ('ValueError', 'DeprecationWarning'):
            raise Skip('expects a test-local exception class')
        if len(statement.body) != 1:
            raise Skip('non-literal argument')
        inner = statement.body[0]
        value = inner.value if isinstance(inner, (ast.Expr, ast.Assign)) else None
        if value is None:
            raise Skip('non-literal argument')
        candidate = invalid_from_call(evaluate(value, env), error, env)
        if error == 'DeprecationWarning':
            notes = candidate.notes + ['dateutil only warns (DeprecationWarning) and still expands the rule; RFC 5545 forbids COUNT together with UNTIL.']
            candidate = candidate._replace(notes=notes)
        return candidate


NO_INSTANCE_NOTE = ('dateutil rejects this rule because no instance after DTSTART can ever match (BYxxx values never meet the '
                    'INTERVAL steps). RFC 5545 does not make the rule invalid; an RFC reading gives no further instances.')


def invalid_notes(candidate):
    notes = list(candidate.notes)
    text = '\n'.join(candidate.recurrence) if candidate.recurrence else candidate.rule
    skip = None
    if re.search(r'FREQ=(HOURLY|MINUTELY|SECONDLY);INTERVAL=\d+;BY(HOUR|MINUTE|SECOND)=', text) or \
            re.search(r'FREQ=(MINUTELY|SECONDLY);INTERVAL=\d+;BY(HOUR|MINUTE)=[\d,]+;COUNT=', text):
        skip = NO_INSTANCE_NOTE
    if not notes and skip is None:
        return None
    if skip is not None:
        result = {'skip': skip}
        if notes:
            result['info'] = ' '.join(notes)
        return result
    return {'info': ' '.join(notes)}


def rfc_remarks(lines, expected):
    """Static RFC 5545 checks as (key, text): "suspicious" for combinations the RFC forbids, "info" for open points."""
    remarks = []
    dtstart = lines[0]
    is_date = dtstart.startswith('DTSTART;VALUE=DATE:')
    dtstart_value = dtstart.split(':', 1)[1]
    for line in lines[1:]:
        if not line.startswith(('RRULE:', 'EXRULE:')):
            continue
        parts = dict(part.split('=', 1) for part in line.split(':', 1)[1].split(';'))
        freq = parts.get('FREQ')
        byday = parts.get('BYDAY', '')
        numeric_byday = any(re.match(r'^[+-]?\d', day) for day in byday.split(',') if day)
        if numeric_byday and freq not in ('MONTHLY', 'YEARLY'):
            remarks.append(('suspicious', 'RFC 5545 forbids a numeric BYDAY with FREQ=%s; dateutil ignores the number.' % freq))
        if numeric_byday and freq == 'YEARLY' and 'BYWEEKNO' in parts:
            remarks.append(('suspicious', 'RFC 5545 forbids a numeric BYDAY with FREQ=YEARLY and BYWEEKNO.'))
        if 'BYMONTHDAY' in parts and freq == 'WEEKLY':
            remarks.append(('suspicious', 'RFC 5545 forbids BYMONTHDAY with FREQ=WEEKLY; dateutil accepts it.'))
        if 'BYYEARDAY' in parts and freq in ('DAILY', 'WEEKLY', 'MONTHLY'):
            remarks.append(('suspicious', 'RFC 5545 forbids BYYEARDAY with FREQ=%s; dateutil accepts it.' % freq))
        if 'BYWEEKNO' in parts and freq != 'YEARLY':
            remarks.append(('suspicious', 'RFC 5545 allows BYWEEKNO only with FREQ=YEARLY; dateutil accepts it.'))
        if 'COUNT' in parts and 'UNTIL' in parts:
            remarks.append(('suspicious', 'RFC 5545 forbids COUNT together with UNTIL.'))
        if 'UNTIL' in parts:
            until_is_date = re.fullmatch(r'\d{8}', parts['UNTIL']) is not None
            if until_is_date != is_date:
                remarks.append(('suspicious', 'UNTIL and DTSTART have different value types (RFC 5545 requires the same type); dateutil compares a DATE UNTIL as midnight.'))
            elif len(parts['UNTIL']) == len(dtstart_value) and parts['UNTIL'] < dtstart_value and not expected:
                remarks.append(('suspicious', 'UNTIL is before DTSTART. dateutil gives no instance; RFC 5545 says DTSTART defines the first instance.'))
        if freq == 'YEARLY' and 'BYMONTHDAY' in parts and not any(k in parts for k in ('BYMONTH', 'BYWEEKNO', 'BYYEARDAY')):
            remarks.append(('info', 'YEARLY with BYMONTHDAY and no BYMONTH: dateutil expands BYMONTHDAY in every month; libical keeps the DTSTART month.'))
        if 'COUNT' in parts and not expected:
            remarks.append(('info', 'No instance exists; dateutil stops the search at year 9999 (datetime.MAXYEAR).'))
        if freq == 'YEARLY' and 'BYWEEKNO' in parts and 'BYDAY' not in parts and 'BYYEARDAY' not in parts and 'BYMONTHDAY' not in parts:
            remarks.append(('info', 'BYWEEKNO without BYDAY: dateutil expands to all days of the week; other libraries (libical) keep only the DTSTART weekday. RFC 5545 does not say.'))
        if is_date and any(k in parts for k in ('BYHOUR', 'BYMINUTE', 'BYSECOND')):
            remarks.append(('suspicious', 'RFC 5545 section 3.3.10: BYHOUR, BYMINUTE and BYSECOND MUST be ignored with a DATE DTSTART.'))
    return remarks


def main():
    args = [arg for arg in sys.argv[1:] if arg != '--verbose']
    verbose = len(args) != len(sys.argv) - 1
    if len(args) != 1:
        sys.exit('usage: python3 -I %s <dateutil clone> [--verbose]' % sys.argv[0])
    clone = args[0]
    corpuslib.require_head(clone, SHA)
    tree = ast.parse(corpuslib.read_text(os.path.join(clone, TEST_FILE)), filename=TEST_FILE)
    extractor = Extractor()
    extractor.run(tree)

    cases = []
    for index, (description, candidate) in enumerate(extractor.cases, start=1):
        remarks = rfc_remarks(candidate.recurrence, candidate.expected)
        suspicious = [text for key, text in remarks if key == 'suspicious']
        info = list(candidate.notes) + [text for key, text in remarks if key == 'info']
        notes = {}
        if suspicious:
            notes['suspicious'] = ' '.join(suspicious)
        if info:
            notes['info'] = ' '.join(info)
        cases.append(corpuslib.case(
            corpuslib.numbered(SOURCE, index),
            description,
            candidate.recurrence,
            candidate.expected,
            notes=notes or None,
        ))
    invalid = []
    for index, (description, candidate) in enumerate(extractor.invalid, start=1):
        invalid.append(corpuslib.invalid_case(
            corpuslib.numbered(SOURCE + '-invalid', index),
            description,
            candidate.error,
            rule=candidate.rule,
            recurrence=candidate.recurrence,
            notes=invalid_notes(candidate),
        ))

    url = corpuslib.github_url(REPO, SHA, TEST_FILE)
    corpuslib.write_json(corpuslib.data_path(SOURCE, 'cases.json'), corpuslib.cases_document(
        SOURCE, url, LICENSE, corpuslib.POLICY_ONLY_IF_MATCHING, cases))
    corpuslib.write_json(corpuslib.data_path(SOURCE, 'invalid.json'), corpuslib.invalid_document(
        SOURCE, url, LICENSE, invalid))

    print('cases.json: %d cases' % len(cases))
    print('invalid.json: %d cases' % len(invalid))
    print('skipped: %d' % sum(extractor.skips.values()))
    for reason, count in sorted(extractor.skips.items(), key=lambda item: (-item[1], item[0])):
        print('  %4d  %s' % (count, reason))
        if verbose:
            print('        ' + ', '.join(extractor.skip_examples[reason]))


if __name__ == '__main__':
    main()
