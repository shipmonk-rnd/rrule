"""Generate data/libical/cases.json from the libical icalrecur_test*.txt files.

Usage: python3 -I tests/Corpus/tools/extract_libical.py <libical clone>

The tool also copies the original .txt files and the MPL-2.0 text unchanged into data/libical/.
The block parser follows src/test/icalrecur_test.c: '#' lines are comments, a blank line ends
a block, and a block has RRULE:, DTSTART:, an optional START-AT: and INSTANCES:.
"""

import os
import re
import shutil
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'libical'
REPO = 'libical/libical'
SHA = 'a298354d59a550afe63d44ee7760f2afea599a47'
TEST_DIR = 'src/test'
FILES = [
    'icalrecur_test.txt',
    'icalrecur_test_rscale.txt',
    'icalrecur_test_rscale_withicu.txt',
    'icalrecur_test_rscale_withicu_dangi.txt',
]
LICENSE_FILE = 'LICENSES/MPL-2.0.txt'
UNIMPLEMENTED_NOTE = (
    'libical does not implement BYWEEKNO together with BYMONTH and BYMONTHDAY. The rule is valid RFC 5545; '
    'dateutil and Ical.Net give 20241230,20251230,20301230,20311230.'
)
UNKNOWN_RSCALE_NOTE = 'Unknown RSCALE value. RFC 7529 section 6 lets an implementation reject the component.'
TRAILING_SEPARATOR_NOTE = 'The RRULE value ends with ";". The RFC 5545 recur grammar has no empty rule part; libical accepts it.'


def parse_blocks(text):
    blocks = []
    comments = []
    current = {}
    line_no = 0
    start_line = 0

    def flush():
        if 'RRULE' in current:
            blocks.append((start_line, list(comments), dict(current)))
        current.clear()
        comments.clear()

    for line_no, line in enumerate(text.split('\n'), 1):
        if line == '':
            flush()
            continue
        if line.startswith('#'):
            comments.append(line[1:].strip())
            continue
        key, separator, value = line.partition(':')
        if separator == '' or key not in ('RRULE', 'DTSTART', 'START-AT', 'INSTANCES'):
            sys.exit('unexpected line %d: %r' % (line_no, line))
        if key in current:
            sys.exit('duplicate %s at line %d' % (key, line_no))
        if not current:
            start_line = line_no
        current[key] = value
    flush()
    return blocks


def rscale(rule):
    match = re.search(r'(?:^|;)RSCALE=([^;]+)', rule)
    return match.group(1).upper() if match else None


def build_case(index, file_name, start_line, comments, block):
    rule = block['RRULE']
    instances = block['INSTANCES'].strip()
    description = ' '.join(comments) if comments else 'No comment in the source; RRULE:' + rule
    description = '%s [%s:%d]' % (description, file_name, start_line)
    expected = []
    expected_error = None
    notes = None
    if instances.startswith('***'):
        expected_error = instances[3:].strip()
    elif instances != '':
        expected = instances.split(',')
    scale = rscale(rule)
    if scale is not None and scale != 'GREGORIAN':
        if file_name.startswith('icalrecur_test_rscale_withicu'):
            notes = {'skip': 'non-Gregorian RSCALE (%s); libical computes it with ICU' % scale}
        elif expected_error is not None:
            notes = {'info': UNKNOWN_RSCALE_NOTE}
        else:
            sys.exit('unexpected non-Gregorian case at %s:%d' % (file_name, start_line))
    elif expected_error is not None:
        notes = {'skip': UNIMPLEMENTED_NOTE}
    elif rule.endswith(';'):
        notes = {'suspicious': TRAILING_SEPARATOR_NOTE}
    return corpuslib.case(
        corpuslib.numbered(SOURCE, index),
        description,
        [corpuslib.dtstart_line(block['DTSTART']), 'RRULE:' + rule],
        expected,
        start_at=block.get('START-AT'),
        expected_error=expected_error,
        notes=notes,
    )


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: extract_libical.py <libical clone>')
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    cases = []
    original_dir = corpuslib.data_path(SOURCE, 'original')
    os.makedirs(original_dir, exist_ok=True)
    for file_name in FILES:
        path = os.path.join(clone, TEST_DIR, file_name)
        shutil.copyfile(path, os.path.join(original_dir, file_name))
        for start_line, comments, block in parse_blocks(corpuslib.read_text(path)):
            cases.append(build_case(len(cases) + 1, file_name, start_line, comments, block))
        print('%s: %d cases so far' % (file_name, len(cases)))
    shutil.copyfile(os.path.join(clone, LICENSE_FILE), corpuslib.data_path(SOURCE, 'MPL-2.0.txt'))
    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'cases.json'),
        corpuslib.cases_document(
            SOURCE,
            'https://github.com/%s/tree/%s/%s' % (REPO, SHA, TEST_DIR),
            'MPL-2.0',
            corpuslib.POLICY_ONLY_IF_MATCHING,
            cases,
        ),
    )
    print('%s: %d cases' % (SOURCE, len(cases)))


if __name__ == '__main__':
    main()
