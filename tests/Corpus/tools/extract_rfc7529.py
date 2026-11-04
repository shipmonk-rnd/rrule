"""Generate data/rfc7529-examples/cases.json from the RFC 7529 section 4.3 examples.

Usage: python3 -I tests/Corpus/tools/extract_rfc7529.py <path to rfc7529.txt>

The instances are hand-encoded from the RFC tables. The tool reads the RFC text only to check
that every DTSTART and RRULE line below occurs in section 4.3.
"""

import os
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import corpuslib  # noqa: E402

SOURCE = 'rfc7529-examples'
SOURCE_URL = 'https://www.rfc-editor.org/rfc/rfc7529#section-4.3'
LICENSE = 'BSD-3-Clause'
UNSUPPORTED = 'unsupported calendar scale'

# title, DTSTART, RRULE, instances from the RFC table, non-Gregorian
EXAMPLES = [
    ('4.3.1. Chinese New Year', '20130210', 'RSCALE=CHINESE;FREQ=YEARLY',
        ['20130210', '20140131', '20150219', '20160208', '20170128'], True),
    ('4.3.2. Ethiopic 13th Month', '20130906', 'RSCALE=ETHIOPIC;FREQ=MONTHLY;BYMONTH=13',
        ['20130906', '20140906', '20150906', '20160906', '20170906'], True),
    ('4.3.3. Hebrew Anniversary Starting in a Leap Month', '20140208', 'RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=5L;BYMONTHDAY=8;SKIP=FORWARD',
        ['20140208', '20150227', '20160217', '20170306', '20180223'], True),
    ('4.3.4. Gregorian Leap Day with SKIP: standard iCalendar behaviour without RSCALE', '20120229', 'FREQ=YEARLY',
        ['20120229', '20160229', '20200229'], False),
    ('4.3.4. Gregorian Leap Day with SKIP: RSCALE=GREGORIAN with SKIP=FORWARD', '20120229', 'RSCALE=GREGORIAN;FREQ=YEARLY;SKIP=FORWARD',
        ['20120229', '20130301', '20140301', '20150301', '20160229', '20170301'], False),
]

NOTES = {
    'FREQ=YEARLY': {'info': 'The RFC table lists 20120229 and 20160229; the prose adds 2020 ("leap years (2016, 2020, etc.)").'},
}


def check_against_rfc(rfc_path, lines):
    text = corpuslib.read_text(rfc_path)
    start = text.index('4.3.  Examples\n')
    end = text.index('5.  Registering Calendar Systems\n', start)
    section = text[start:end]
    found = {line.strip() for line in section.split('\n')}
    missing = [line for line in lines if line not in found]
    if missing:
        sys.exit('lines not found in RFC 7529 section 4.3:\n' + '\n'.join(missing))
    for value in re.findall(r'(?<![}\w])(20\d{6})\b', section):
        if not any(value in example[3] for example in EXAMPLES):
            sys.exit('RFC table value %s is not encoded' % value)


def main():
    if len(sys.argv) != 2:
        sys.exit('usage: extract_rfc7529.py <rfc7529.txt>')
    cases = []
    all_lines = []
    for title, dtstart, rule, instances, non_gregorian in EXAMPLES:
        lines = [corpuslib.dtstart_line(dtstart), 'RRULE:' + rule]
        all_lines.extend(lines)
        case_id = corpuslib.numbered(SOURCE, len(cases) + 1)
        if non_gregorian:
            cases.append(corpuslib.case(
                case_id, title, lines, [],
                expected_error=UNSUPPORTED,
                notes={
                    'skip': 'non-Gregorian RSCALE',
                    'rfcInstances': ','.join(instances),
                    'rfcInstancesTruncated': 'true',
                },
            ))
        else:
            cases.append(corpuslib.case(case_id, title, lines, instances, truncated=True, notes=NOTES.get(rule)))
    check_against_rfc(sys.argv[1], all_lines)
    corpuslib.write_json(
        corpuslib.data_path(SOURCE, 'cases.json'),
        corpuslib.cases_document(SOURCE, SOURCE_URL, LICENSE, corpuslib.POLICY_ALWAYS, cases),
    )
    print('%s: %d cases' % (SOURCE, len(cases)))


if __name__ == '__main__':
    main()
