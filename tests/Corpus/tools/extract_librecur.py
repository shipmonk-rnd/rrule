"""Generate data/lib-recur/invalid.json from lib-recur InvalidRuleTest.java.

Usage: python3 -I tests/Corpus/tools/extract_librecur.py <lib-recur clone>

Every rule in that test must throw InvalidRecurrenceRuleException in RFC5545_STRICT mode.
The lax-mode result of the test goes to "notes".
"""

import os
import re
import shutil
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'lib-recur'
REPO = 'dmfs/lib-recur'
SHA = '5ba0c83529d47e8020fa3b6411689841edca6242'
PATH = 'src/test/java/org/dmfs/rfc5545/recur/InvalidRuleTest.java'
ERROR = 'InvalidRecurrenceRuleException (RFC5545_STRICT mode)'

ENTRY_RE = re.compile(
    r'TEST_RULES\.add\(new InvalidTestRule\("((?:[^"\\]|\\.)*)",\s*(null|"((?:[^"\\]|\\.)*)")\)\);[ \t]*(?://[ \t]*(.*))?'
)
MORE_RULES_MARKER = '/* Add more invalid rules */'


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: extract_librecur.py <lib-recur clone>')
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    text = corpuslib.read_text(os.path.join(clone, PATH))
    body = text[text.index('static\n'):]
    more_rules_at = body.index(MORE_RULES_MARKER)
    cases = []
    seen = set()
    duplicates = 0
    for match in ENTRY_RE.finditer(body):
        rule = match.group(1).encode().decode('unicode_escape')
        if rule in seen:
            duplicates += 1
            continue
        seen.add(rule)
        if match.group(4):
            description = match.group(4).strip()
        elif match.start() < more_rules_at:
            description = 'Invalid rule found in the wild'
        else:
            description = 'Invalid rule'
        if match.group(2) == 'null':
            notes = {'laxMode': 'lib-recur also throws in RFC5545_LAX mode'}
        else:
            notes = {'laxMode': 'lib-recur RFC5545_LAX mode parses it as ' + match.group(3)}
        cases.append(corpuslib.invalid_case(
            corpuslib.numbered(SOURCE + '-invalid', len(cases) + 1),
            description,
            ERROR,
            rule=rule,
            notes=notes,
        ))
    os.makedirs(os.path.dirname(corpuslib.data_path(SOURCE, 'invalid.json')), exist_ok=True)
    shutil.copyfile(os.path.join(clone, 'LICENSE'), corpuslib.data_path(SOURCE, 'LICENSE-Apache-2.0.txt'))
    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'invalid.json'),
        corpuslib.invalid_document(SOURCE, corpuslib.github_url(REPO, SHA, PATH), 'Apache-2.0', cases),
    )
    print('%s: %d invalid rules, %d exact duplicates dropped' % (SOURCE, len(cases), duplicates))


if __name__ == '__main__':
    main()
