"""Generate data/pycalendar/cases.json from ccs-pycalendar rrule_examples.json.

Usage: python3 -I tests/Corpus/tools/extract_pycalendar.py <ccs-pycalendar clone>

test_recurrence.py testExampleRules expands each rule from "start" within the period
[start, end) and compares all instances with "results". The rules have no COUNT or UNTIL,
so "results" is a prefix of an infinite sequence: the cases are truncated.
"""

import datetime
import json
import os
import shutil
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'pycalendar'
REPO = 'apple/ccs-pycalendar'
SHA = 'a12dd4e1ce8822b022d4abf2cfe6cc93902ff03f'
PATH = 'src/pycalendar/icalendar/tests/rrule_examples.json'
SUSPICIOUS = {
    1: (
        'BYWEEKNO without BYDAY: pycalendar takes the weekday of 1 January of each year (20140101 is a Wednesday, '
        '20160108 a Friday). RFC 5545 takes missing parts from DTSTART (a Tuesday); libical-058 has the same rule '
        'and gives Tuesdays.'
    ),
}


def is_valid_date_time(value):
    try:
        datetime.datetime.strptime(value, '%Y%m%dT%H%M%S')
    except ValueError:
        return False
    return True


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: extract_pycalendar.py <ccs-pycalendar clone>')
    clone = sys.argv[1]
    corpuslib.require_head(clone, SHA)
    with open(os.path.join(clone, PATH), encoding='utf-8') as handle:
        examples = json.load(handle)
    cases = []
    skipped = []
    for number, example in enumerate(examples, 1):
        if set(example) != {'rule', 'start', 'end', 'results'}:
            sys.exit('unexpected keys in entry %d' % number)
        if 'COUNT=' in example['rule'] or 'UNTIL=' in example['rule']:
            sys.exit('entry %d is bounded; the truncated flag would be wrong' % number)
        if not is_valid_date_time(example['start']):
            skipped.append('entry %d: start %s is not a valid date (pycalendar normalizes it)' % (number, example['start']))
            continue
        cases.append(corpuslib.case(
            corpuslib.numbered(SOURCE, len(cases) + 1),
            'rrule_examples.json entry %d: %s' % (number, example['rule']),
            [corpuslib.dtstart_line(example['start']), 'RRULE:' + example['rule']],
            example['results'],
            truncated=True,
            notes=dict(
                [('suspicious', SUSPICIOUS[number])] if number in SUSPICIOUS else [],
                info='pycalendar lists every instance in the period [%s, %s).' % (example['start'], example['end']),
            ),
        ))
    os.makedirs(os.path.dirname(corpuslib.data_path(SOURCE, 'cases.json')), exist_ok=True)
    shutil.copyfile(os.path.join(clone, 'LICENSE.txt'), corpuslib.data_path(SOURCE, 'LICENSE-Apache-2.0.txt'))
    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'cases.json'),
        corpuslib.cases_document(SOURCE, corpuslib.github_url(REPO, SHA, PATH), 'Apache-2.0', corpuslib.POLICY_ONLY_IF_MATCHING, cases),
    )
    print('%s: %d cases' % (SOURCE, len(cases)))
    for line in skipped:
        print('skipped ' + line)


if __name__ == '__main__':
    main()
