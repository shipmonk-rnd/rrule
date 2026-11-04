"""Extract rust-rrule RFC and daylight-saving tests into tests/Corpus/data/rust-rrule/cases.json.

Usage: python3 -I tests/Corpus/tools/extract_rust_rrule.py <rust-rrule clone>

The tool reads rrule/src/tests/rfc_tests.rs and daylight_saving.rs as text. It does not run Rust code.
"""

import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'rust-rrule'
REPO = 'fmeringdal/rust-rrule'
SHA = '1c3420ede20e6190040804121e1b5e1eddac6493'
FILES = ['rrule/src/tests/rfc_tests.rs', 'rrule/src/tests/daylight_saving.rs']

STRING_RE = re.compile(r'"((?:[^"\\]|\\.)*)"', re.S)
TEST_RE = re.compile(r'((?:[ \t]*///[^\n]*\n)*)[ \t]*#\[test\]\s*fn (\w+)\(\)\s*\{')
RULE_BINDING_RE = re.compile(r'let (?:mut )?(\w+)(?:\s*:\s*[\w<>]+)?\s*=\s*"')
LIMIT_RE = re.compile(r'\A[^;]*?\.(?:all\((\w+(?:::MAX)?)\)|(all_unchecked)\(\))', re.S)
DERIVED_RE = re.compile(r'let (?:mut )?(\w+)\s*=\s*(\w+)\s*\.(?:all\((\w+(?:::MAX)?)\)|(all_unchecked)\(\))')
CHECK_RE = re.compile(r'check_occurrences\(\s*&(\w+)\s*,\s*&')
RFC3339_RE = re.compile(r'^(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)(Z|[+-]\d\d:\d\d)$')
LOOP_TOKEN_RE = re.compile(
    r'for\s+(?P<var>\w+)\s+in\s+(?P<lo>\d+)\.\.=(?P<hi>\d+)\s*\{'
    r'|format!\(\s*"(?P<fmt>(?:[^"\\]|\\.)*)"\s*,\s*(?P<args>[^)]*)\)'
    r'|(?P<close>\})'
)


def decode_rust_string(raw):
    text = re.sub(r'\\\n[ \t]*', '', raw)
    return text.replace('\\n', '\n').replace('\\"', '"').replace('\\\\', '\\')


def strip_line_comments(text):
    """Remove // comments outside string literals."""
    out = []
    i = 0
    while i < len(text):
        char = text[i]
        if char == '"':
            match = STRING_RE.match(text, i)
            out.append(match.group(0))
            i = match.end()
        elif text.startswith('//', i):
            end = text.find('\n', i)
            i = len(text) if end < 0 else end
        else:
            out.append(char)
            i += 1
    return ''.join(out)


def matching(text, start, open_char, close_char):
    depth = 0
    i = start
    while i < len(text):
        char = text[i]
        if char == '"':
            i = STRING_RE.match(text, i).end()
            continue
        if char == open_char:
            depth += 1
        elif char == close_char:
            depth -= 1
            if depth == 0:
                return i
        i += 1
    raise ValueError('unbalanced %s' % open_char)


def string_list(text):
    return [decode_rust_string(m.group(1)) for m in STRING_RE.finditer(text)]


def evaluate_loops(text):
    """Evaluate nested `for x in a..=b { ... format!("...", x) ... }` blocks that build an expected list."""
    tokens = list(LOOP_TOKEN_RE.finditer(text))
    position = [0]

    def block():
        nodes = []
        while position[0] < len(tokens):
            token = tokens[position[0]]
            position[0] += 1
            if token.group('close'):
                return nodes
            if token.group('var'):
                nodes.append(('loop', token.group('var'), int(token.group('lo')), int(token.group('hi')), block()))
            else:
                args = [a.strip() for a in token.group('args').split(',') if a.strip()]
                nodes.append(('format', decode_rust_string(token.group('fmt')), args))
        return nodes

    def run(nodes, scope, out):
        for node in nodes:
            if node[0] == 'loop':
                for value in range(node[2], node[3] + 1):
                    run(node[4], dict(scope, **{node[1]: value}), out)
            else:
                values = iter(scope[name] for name in node[2])
                out.append(re.sub(r'\{(:02)?\}', lambda m: ('%02d' if m.group(1) else '%d') % next(values), node[1]))
        return out

    return run(block(), {}, [])


def expected_value(rfc3339, dtstart_line):
    match = RFC3339_RE.match(rfc3339)
    if match is None:
        raise ValueError('not an RFC 3339 value: %r' % rfc3339)
    year, month, day, hour, minute, second, offset = match.groups()
    local = '%s%s%sT%s%s%s' % (year, month, day, hour, minute, second)
    if ';TZID=' in dtstart_line:
        return local + ('+0000' if offset == 'Z' else offset.replace(':', ''))
    if dtstart_line.endswith('Z'):
        if offset not in ('Z', '+00:00'):
            raise ValueError('UTC DTSTART with offset %s' % offset)
        return local + 'Z'
    raise ValueError('unsupported DTSTART %r' % dtstart_line)


def rule_property(lines, name):
    for line in lines:
        if line.startswith('RRULE:'):
            for part in line[6:].split(';'):
                if part.split('=')[0] == name:
                    return part.split('=', 1)[1]
    return None


def extract_test(name, doc, body, file_name):
    body = strip_line_comments(body)
    rules = {}
    for match in RULE_BINDING_RE.finditer(body):
        literal = STRING_RE.match(body, match.end() - 1)
        text = decode_rust_string(literal.group(1))
        limit_match = LIMIT_RE.match(body[literal.end():])
        limit = None
        if limit_match:
            limit = 'unchecked' if limit_match.group(2) else limit_match.group(1)
        rules[match.group(1)] = [text, limit]
    for match in DERIVED_RE.finditer(body):
        if match.group(2) in rules:
            rules[match.group(1)] = [rules[match.group(2)][0], 'unchecked' if match.group(4) else match.group(3)]
    expected_lists = {}
    vec_match = re.search(r'let (?:mut )?expected\s*=\s*vec!\[', body)
    if vec_match:
        close = matching(body, vec_match.end() - 1, '[', ']')
        items = string_list(body[vec_match.end():close])
        if items:
            expected_lists['expected'] = items
        else:
            first_check = body.find('check_occurrences', close)
            expected_lists['expected'] = evaluate_loops(body[close + 1:first_check])
    results = []
    for match in CHECK_RE.finditer(body):
        variable = match.group(1)
        rest = body[match.end():]
        if rest.startswith('['):
            close = matching(rest, 0, '[', ']')
            expected = string_list(rest[1:close])
        else:
            expected = expected_lists[re.match(r'\w+', rest).group(0)]
        text, limit = rules[variable]
        results.append((variable, text, limit, expected))
    if not results:
        raise ValueError('%s: no check_occurrences call' % name)
    cases = []
    for variable, text, limit, expected in results:
        lines = text.split('\n')
        dtstart = [line for line in lines if line.startswith('DTSTART')]
        if len(dtstart) != 1:
            raise ValueError('%s: want one DTSTART' % name)
        is_finite = rule_property(lines, 'COUNT') is not None or rule_property(lines, 'UNTIL') is not None
        if limit is None:
            raise ValueError('%s: no .all() limit found' % name)
        truncated = False
        if limit not in ('u16::MAX', 'unchecked'):
            if int(limit) == len(expected):
                if is_finite:
                    raise ValueError('%s: finite rule cut at its limit; cannot tell if truncated' % name)
                truncated = True
            elif not is_finite:
                raise ValueError('%s: infinite rule returned fewer instances than its limit' % name)
        elif not is_finite:
            raise ValueError('%s: infinite rule without a limit' % name)
        doc_lines = [line.strip()[3:].strip() for line in doc.split('\n')]
        description = ' '.join(line for line in doc_lines if line) or name
        if variable != 'dates':
            description += ' (alternative rule)'
        cases.append({
            'test': '%s::%s' % (os.path.basename(file_name), name),
            'description': '%s [%s]' % (description, name),
            'recurrence': lines,
            'expected': [expected_value(value, dtstart[0]) for value in expected],
            'truncated': truncated,
        })
    return cases


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    extracted = []
    for file_name in FILES:
        text = corpuslib.read_text(os.path.join(clone, file_name))
        for match in TEST_RE.finditer(text):
            close = matching(text, match.end() - 1, '{', '}')
            extracted.extend(extract_test(match.group(2), match.group(1), text[match.end():close], file_name))
    cases = []
    for index, item in enumerate(extracted, 1):
        notes = None
        if item['test'].startswith('rfc_tests.rs::') and 'UNTIL=19970902T210000Z' in item['recurrence'][-1]:
            notes = {'info': 'RFC 5545 erratum 3883 applied (UNTIL=19970902T210000Z)'}
        cases.append(corpuslib.case(
            corpuslib.numbered(SOURCE, index),
            item['description'],
            item['recurrence'],
            item['expected'],
            truncated=item['truncated'],
            notes=notes,
        ))
    document = corpuslib.cases_document(
        SOURCE,
        'https://github.com/%s/tree/%s/rrule/src/tests' % (REPO, SHA),
        'MIT',
        corpuslib.POLICY_ONLY_IF_MATCHING,
        cases,
    )
    corpuslib.write_json(corpuslib.data_path(SOURCE, 'cases.json'), document)
    print('%s: %d cases' % (SOURCE, len(cases)))


if __name__ == '__main__':
    main()
