"""Generate data/php-rrule/invalid.json from the php-rrule invalidRules and invalidRfcStrings providers.

Usage: python3 -I tests/Corpus/tools/extract_phprrule.py <php-rrule clone>

invalidRules gives php-rrule option arrays. The tool writes each array as an RRULE value
("KEY=value" parts joined with ";", in source order). Values that are not text (PHP arrays of
arrays, null, objects) have no RRULE text, so the tool leaves those entries out.
invalidRfcStrings gives RFC strings. php-rrule trims each line, and so does the tool.
Both tests expect InvalidArgumentException.
"""

import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'php-rrule'
REPO = 'rlanvin/php-rrule'
SHA = '93a083db12dcb6f58e4840392a22e158ce96f1ff'
PATH = 'tests/RRuleTest.php'
ERROR = 'InvalidArgumentException'

TOKEN_RE = re.compile(r"""
    (?P<ws>\s+)
    | (?P<comment>//[^\n]*)
    | (?P<string>'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")
    | (?P<number>-?\d+(?:\.\d+)?)
    | (?P<new>new\s+\w+\(\))
    | (?P<word>\w+)
    | (?P<arrow>=>)
    | (?P<punct>[\[\](),])
""", re.X)

NOTES = {
    'UNTIL=2015-07-01': 'php-rrule takes UNTIL as a PHP date string; the source value is 2015-07-01.',
}
MULTIPLE_RRULE_NOTE = (
    'php-rrule: the RRule class takes one RRULE; RSet takes several. RFC 5545 section 3.8.5.3 says RRULE '
    '"SHOULD NOT be specified more than once", so a parser for a recurrence set can accept it.'
)
EXRULE_NOTE = (
    'php-rrule: the RRule class rejects EXRULE; RSet takes it. RFC 5545 Appendix A.3 says EXRULE '
    '"can no longer be specified in a component".'
)


class Unsupported(Exception):
    pass


def tokenize(text):
    tokens = []
    position = 0
    while position < len(text):
        match = TOKEN_RE.match(text, position)
        if match is None:
            raise ValueError('cannot tokenize at %r' % text[position:position + 40])
        position = match.end()
        kind = match.lastgroup
        if kind == 'ws':
            continue
        tokens.append((kind, match.group(kind)))
    return tokens


def php_string(literal):
    body = literal[1:-1]
    if literal[0] == "'":
        return body.replace("\\'", "'").replace('\\\\', '\\')
    return body.encode().decode('unicode_escape')


class Parser:
    def __init__(self, tokens):
        self.tokens = tokens
        self.index = 0
        self.comments = []

    def peek(self):
        while self.index < len(self.tokens) and self.tokens[self.index][0] == 'comment':
            self.comments.append(self.tokens[self.index][1][2:].strip())
            self.index += 1
        return self.tokens[self.index] if self.index < len(self.tokens) else (None, None)

    def take(self, value=None):
        token = self.peek()
        if value is not None and token[1] != value:
            raise ValueError('expected %r, got %r' % (value, token))
        self.index += 1
        return token

    def value(self):
        kind, text = self.peek()
        if kind == 'string':
            self.take()
            return php_string(text)
        if kind == 'number':
            self.take()
            return text
        if kind == 'new':
            self.take()
            return Unsupported('object')
        if kind == 'word' and text == 'null':
            self.take()
            return Unsupported('null')
        if (kind == 'word' and text == 'array') or text == '[':
            return self.array()
        raise ValueError('unexpected token %r' % (text,))

    def array(self):
        if self.peek()[1] == 'array':
            self.take()
            self.take('(')
            closing = ')'
        else:
            self.take('[')
            closing = ']'
        items = []
        while self.peek()[1] != closing:
            first = self.value()
            if self.peek()[1] == '=>':
                self.take()
                items.append((first, self.value()))
            else:
                items.append((None, first))
            if self.peek()[1] == ',':
                self.take()
        self.take(closing)
        return items

    def entries(self):
        """Parse the provider's returned array; yield (name, comments, value)."""
        if self.peek()[1] == 'array':
            self.take()
            self.take('(')
            closing = ')'
        else:
            self.take('[')
            closing = ']'
        while self.peek()[1] != closing:
            comments = list(self.comments)
            self.comments.clear()
            first = self.value()
            name = None
            if self.peek()[1] == '=>':
                self.take()
                name = first
                first = self.value()
            yield name, comments, first
            if self.peek()[1] == ',':
                self.take()
        self.take(closing)


def provider_body(text, name):
    start = text.index('public function %s()' % name)
    start = text.index('return ', start) + len('return ')
    end = text.index(';\n', start)
    return text[start:end]


def rule_text(options):
    parts = []
    for key, value in options:
        if isinstance(value, Unsupported):
            raise value
        if isinstance(value, list):
            if any(isinstance(item, (list, Unsupported)) or item_key is not None for item_key, item in value):
                raise Unsupported('nested array')
            if not value:
                raise Unsupported('empty array')
            value = ','.join(item for _, item in value)
        if key == 'UNTIL' and re.fullmatch(r'\d{4}-\d\d-\d\d', value):
            value = value.replace('-', '')
            parts.append(('UNTIL=%s' % value, NOTES['UNTIL=2015-07-01']))
            continue
        parts.append(('%s=%s' % (key, value), None))
    return ';'.join(part for part, _ in parts), [note for _, note in parts if note]


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: extract_phprrule.py <php-rrule clone>')
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    text = corpuslib.read_text(os.path.join(clone, PATH))
    cases = []
    skipped = {}

    for name, comments, entry in Parser(tokenize(provider_body(text, 'invalidRules'))).entries():
        options = entry[0][1]
        try:
            rule, notes = rule_text(options)
        except Unsupported as error:
            skipped.setdefault('invalidRules: option value is a PHP %s, not text' % error, []).append(name or '(unnamed)')
            continue
        description = name or (' '.join(comments) if comments else 'Invalid option array (invalidRules)')
        cases.append(corpuslib.invalid_case(
            corpuslib.numbered(SOURCE + '-invalid', len(cases) + 1),
            description,
            ERROR,
            rule=rule,
            notes={'info': ' '.join(notes)} if notes else None,
        ))

    group = 'Invalid RFC string (invalidRfcStrings)'
    for name, comments, entry in Parser(tokenize(provider_body(text, 'invalidRfcStrings'))).entries():
        value = entry[0][1]
        lines = [line.strip() for line in value.split('\n')]
        if comments:
            group = ' '.join(comments)
        description = group
        note = EXRULE_NOTE if 'EXRULE:' in value else (MULTIPLE_RRULE_NOTE if value.count('RRULE:') > 1 else None)
        notes = {'info': note} if note else None
        case_id = corpuslib.numbered(SOURCE + '-invalid', len(cases) + 1)
        if len(lines) == 1:
            cases.append(corpuslib.invalid_case(case_id, description, ERROR, rule=lines[0], notes=notes))
        else:
            cases.append(corpuslib.invalid_case(case_id, description, ERROR, recurrence=lines, notes=notes))

    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'invalid.json'),
        corpuslib.invalid_document(SOURCE, corpuslib.github_url(REPO, SHA, PATH), 'MIT', cases),
    )
    print('%s: %d invalid entries' % (SOURCE, len(cases)))
    for reason, names in sorted(skipped.items()):
        print('skipped %d (%s): %s' % (len(names), reason, ', '.join(names)))


if __name__ == '__main__':
    main()
