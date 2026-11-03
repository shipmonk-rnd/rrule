"""Shared helpers for the corpus extraction tools (Python 3 standard library only).

The tools run with `python3 -I`, which does not put the script directory on sys.path.
Each tool therefore adds its own directory before it imports this module.
"""

import json
import os
import re
import sys

TOOLS_DIR = os.path.dirname(os.path.abspath(__file__))
DATA_DIR = os.path.normpath(os.path.join(TOOLS_DIR, '..', 'data'))

POLICY_ALWAYS = 'Always'
POLICY_ONLY_IF_MATCHING = 'OnlyIfMatching'

WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']


def github_url(repo, sha, path):
    return 'https://github.com/%s/blob/%s/%s' % (repo, sha, path)


def read_text(path):
    with open(path, 'r', encoding='utf-8', newline='') as handle:
        return handle.read().replace('\r\n', '\n')


def clone_head(clone_dir):
    """Resolve HEAD of a git clone from its files, without running git inside the untrusted clone."""
    git_dir = os.path.join(clone_dir, '.git')
    head = read_text(os.path.join(git_dir, 'HEAD')).strip()
    if not head.startswith('ref: '):
        return head
    ref = head[5:]
    ref_path = os.path.join(git_dir, ref)
    if os.path.exists(ref_path):
        return read_text(ref_path).strip()
    packed = os.path.join(git_dir, 'packed-refs')
    if os.path.exists(packed):
        for line in read_text(packed).split('\n'):
            parts = line.split(' ')
            if len(parts) == 2 and parts[1] == ref:
                return parts[0]
    raise RuntimeError('cannot resolve HEAD of %s' % clone_dir)


def require_head(clone_dir, sha):
    actual = clone_head(clone_dir)
    if actual != sha:
        sys.exit('%s is at %s, expected %s; check out the pinned commit first' % (clone_dir, actual, sha))


def case(case_id, description, recurrence, expected, start_at=None, expected_error=None, truncated=False, notes=None, dt_start_policy=None):
    if isinstance(recurrence, (list, tuple)):
        recurrence = '\n'.join(recurrence)
    result = {
        'id': case_id,
        'description': description,
        'recurrence': recurrence,
        'startAt': start_at,
        'expected': list(expected),
        'expectedError': expected_error,
        'truncated': truncated,
        'notes': notes,
    }
    if dt_start_policy is not None:
        result['dtStartPolicy'] = dt_start_policy
    return result


def invalid_case(case_id, description, expected_error, rule=None, recurrence=None, notes=None):
    if (rule is None) == (recurrence is None):
        raise ValueError('give exactly one of rule and recurrence')
    result = {'id': case_id, 'description': description}
    if rule is not None:
        result['rule'] = rule
    else:
        if isinstance(recurrence, (list, tuple)):
            recurrence = '\n'.join(recurrence)
        result['recurrence'] = recurrence
    result['expectedError'] = expected_error
    result['notes'] = notes
    return result


def cases_document(source, source_url, license_id, dt_start_policy, cases):
    return {
        'source': source,
        'sourceUrl': source_url,
        'license': license_id,
        'dtStartPolicy': dt_start_policy,
        'cases': cases,
    }


def invalid_document(source, source_url, license_id, cases):
    return {
        'source': source,
        'sourceUrl': source_url,
        'license': license_id,
        'cases': cases,
    }


def write_json(path, document):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    text = json.dumps(document, indent=2, ensure_ascii=False) + '\n'
    with open(path, 'w', encoding='utf-8', newline='\n') as handle:
        handle.write(text)


def data_path(source, file_name):
    return os.path.join(DATA_DIR, source, file_name)


def dtstart_line(value, tzid=None):
    """Build a DTSTART line; an 8-digit value becomes a DATE."""
    if re.fullmatch(r'\d{8}', value):
        if tzid is not None:
            raise ValueError('DATE with TZID')
        return 'DTSTART;VALUE=DATE:' + value
    if tzid is not None:
        if value.endswith('Z'):
            raise ValueError('UTC value with TZID')
        return 'DTSTART;TZID=%s:%s' % (tzid, value)
    return 'DTSTART:' + value


def numbered(prefix, index, width=3):
    return '%s-%0*d' % (prefix, width, index)
