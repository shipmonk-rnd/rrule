# RRULE test corpus

This directory contains recurrence test cases from RFC examples and from other RRULE implementations.
A PHPUnit runner reads the JSON files. Python tools in `tools/` make the JSON files from pinned upstream sources.

## Layout

- `data/<source>/cases.json`: expansion cases (a recurrence and its expected instances).
- `data/<source>/invalid.json`: input that the source rejects.
- `data/<source>/NOTICE`: origin, copyright and licence of the files in that directory. Some directories also have a full licence text.
- `data/libical/original/`: unchanged copies of the libical test files.
- `tools/extract_*.py`: one tool per source. `tools/corpuslib.py` has the shared helpers.
- `tools/validate.py`: schema and format checks for all data files.
- `tools/verify_rfc5545.py`: an independent brute-force check of the RFC 5545 examples.

## cases.json format

| Field | Meaning |
|---|---|
| `source`, `sourceUrl`, `license` | File level. `sourceUrl` points to the pinned commit. |
| `dtStartPolicy` | File level; a case can override it. `Always`: DTSTART is an instance also when it does not match the rule. `OnlyIfMatching`: the source drops a DTSTART that does not match. |
| `id` | Unique in the corpus, `<source>-NNN`. |
| `description` | The comment or test name from the source. libical and Ical.Net add the source line. |
| `recurrence` | Content lines joined with `\n`: one `DTSTART`, one or more `RRULE`, optional `RDATE`, `EXDATE`, `EXRULE`. |
| `startAt` | libical `START-AT`, sabre `fastForward`: compare only instances at or after this value. |
| `expected` | Instances in ascending order, in the form of DTSTART (see below). |
| `expectedError` | Error text of the source when it rejects the input; `expected` is then `[]`. |
| `truncated` | `true`: `expected` is a prefix of a longer sequence. Compare only the first `count(expected)` instances. |
| `notes` | `null` or an object. See below. |

Value forms in `expected` and `startAt`:
- DATE DTSTART (`DTSTART;VALUE=DATE:YYYYMMDD`): `YYYYMMDD`.
- Floating DTSTART: `YYYYMMDDTHHMMSS`.
- UTC DTSTART: `YYYYMMDDTHHMMSSZ`.
- Zoned DTSTART (`DTSTART;TZID=...`): the local wall time `YYYYMMDDTHHMMSS`. When the source gives the UTC offset, the value has the suffix `+HHMM` or `-HHMM` (rfc5545-examples, rust-rrule). `startAt` has no offset.

Keys in `notes`:
- `skip`: the runner must not run the case (for example non-Gregorian RSCALE, or a rule that only the source rejects).
- `suspicious`: the expectation does not agree with RFC 5545 (for example a combination that the RFC forbids, which the source accepts).
- `info`: other facts the runner can need (for example how a value was converted).
- Source-specific data: `rfcInstances` (rfc7529-examples), `laxMode` (lib-recur).

`invalid.json` uses the same file header without `dtStartPolicy`. Each case has `id`, `description`, `expectedError`, `notes`, and one of these:
- `rule`: an RRULE value without DTSTART.
- `recurrence`: content lines, as in `cases.json`. These lines can be malformed on purpose.

## Runner rules

1. With `startAt`, drop the computed and the expected instances before `startAt`, then compare. Some sources list only the instances from `startAt` on (`ical.net-064`, sabre).
2. Many libical, Ical.Net and ical.js cases have a floating DTSTART with a UTC `UNTIL`. RFC 5545 section 3.3.10 does not allow this. The sources compare `UNTIL` as if DTSTART were UTC. Use a lenient parse for these cases.
3. `Always` is not the same in all sources. In RFC 5545 and sabre, DTSTART counts as one of the `COUNT` instances. Mozilla adds a non-matching DTSTART on top of `COUNT` (COUNT+1 instances); these cases have a `suspicious` note.
4. Ical.Net reads DTSTART as UTC. A floating DTSTART gives the same instances.

## Interpretation splits

The sources do not agree on these points, so no single engine can pass every case:
- YEARLY with BYMONTHDAY and no BYMONTH: libical, Ical.Net, ical.js and Mozilla use the DTSTART month. dateutil uses every month.
- BYWEEKNO without BYDAY: libical, Ical.Net and sabre use the DTSTART weekday. dateutil uses all days of the week. pycalendar uses a third method (`pycalendar-001`).
- INTERVAL with BYWEEKNO: libical and Ical.Net count years from the ISO week-numbering year of DTSTART. dateutil counts calendar years.
- BYSETPOS in the first period: libical and Ical.Net select from the full period and then drop instances before DTSTART. dateutil 4.0 selects only from DTSTART on in the first WEEKLY period (`libical-118`, `libical-129`, `libical-154`, `ical.net-039`).
- Time rule parts with a DATE DTSTART: RFC 5545 section 3.3.10 says to ignore them, and libical does. dateutil applies them.

## Sources

| Source | URL at pinned commit | Licence | Cases | How extracted | Not imported, and why |
|---|---|---|---|---|---|
| rfc5545-examples | [RFC 5545 §3.8.5.3](https://www.rfc-editor.org/rfc/rfc5545#section-3.8.5.3) | IETF TLP 4.e (BSD-3-Clause text) | 42 | Hand-encoded from the prose (`extract_rfc5545.py`). 39 examples; 3 examples give two RRULEs. Verified erratum 3883 applied. `verify_rfc5545.py` recomputes all instances and offsets. | None. Section 3.8.5.3 has no RDATE example; the RDATE examples in §3.8.5.2 have no expansion. |
| rfc7529-examples | [RFC 7529 §4.3](https://www.rfc-editor.org/rfc/rfc7529#section-4.3) | IETF TLP 4.e (BSD-3-Clause text) | 5 | Hand-encoded from the tables (`extract_rfc7529.py`). | None. The 3 non-Gregorian cases have `skip` and `expectedError` "unsupported calendar scale". The xCal and jCal examples have no DTSTART. |
| libical | [libical@a298354](https://github.com/libical/libical/tree/a298354d59a550afe63d44ee7760f2afea599a47/src/test) | MPL-2.0 (of LGPL-2.1-only OR MPL-2.0) | 191 | Block parser for `icalrecur_test*.txt` (157 + 14 + 19 + 1). | None. 21 have `skip`: 20 need ICU (non-Gregorian RSCALE), and libical reports 1 valid rule as UNIMPLEMENTED. |
| ical.net | [ical.net@b7cd906](https://github.com/ical-org/ical.net/blob/b7cd9063576825fd52b26839d1e16f7187d2e023/Ical.Net.Tests/Calendars/Recurrence/RecurrenceTestCases.txt) | MIT | 83 + 1 invalid | Block parser. `EXCEPTION:` is an expected .NET exception (not an EXDATE). Exceptions for RFC "MUST NOT" rules become `expectedError`. The parse-time exception goes to `invalid.json`. | 8: 7 test the Ical.Net year-9999 limit, and 1 depends on `DURATION`. The older libical copy in `Ical.Net.Tests/contrib` duplicates libical. |
| dateutil | [dateutil@a78cbd8](https://github.com/dateutil/dateutil/blob/a78cbd81d5d03b5f126eb36edb92bca78972c804/tests/test_rrule.py) | Apache-2.0 AND BSD-3-Clause | 245 + 15 invalid | `ast` parse (no execution) of literal `rrule()`, `rrulestr()` and `rruleset()` calls compared with literal datetime lists. All 245 match dateutil 4.0.0. | 314: 227 `str()` round trips, 21 BYEASTER, 34 API/cache/pickle tests, 16 `between`/`after`/`before`/`count`/index query tests (+1 assertion), 8 `rrulestr` parser options, 4 sets without RRULE, 1 microsecond DTSTART, 1 BYWEEKDAY alias, 1 RRULE with an X-parameter. |
| rust-rrule | [rust-rrule@1c3420e](https://github.com/fmeringdal/rust-rrule/tree/1c3420ede20e6190040804121e1b5e1eddac6493/rrule/src/tests) | MIT (of MIT OR Apache-2.0) | 44 | Regex over `rfc_tests.rs` and `daylight_saving.rs`. Expected values keep the RFC 3339 offsets. | None. |
| mozilla | [comm-central@4208e23](https://hg.mozilla.org/comm-central/file/4208e230566564f0a89ff6035ca9ff7b1b63e63d/calendar/test/unit/test_recur.js) | MPL-2.0 | 35 | Text scraping of `check_recur()` in `test_rules()`. The tool checks the sha256 of the file. | 8: 4 use `modifyException`, 2 use RECURRENCE-ID components, 2 are SECONDLY/MINUTELY (Mozilla does not expand them). |
| ical.js | [ical.js@7bc1636](https://github.com/kewisch/ical.js/blob/7bc16367692451cea30b2751f4ed5532d709d402/test/recur_iterator_test.js) | MPL-2.0 | 115 | Text scraping of `testRRULE(rule, options)`. | 7 tests that are commented out in the source. |
| sabre | [vobject@5e2f338](https://github.com/sabre-io/vobject/blob/5e2f338811916c03927398bb24e527989de63194/tests/VObject/Recur/RRuleIteratorTest.php) | BSD-3-Clause | 93 | Text scraping of `parse()` calls and data providers. UTC is the default time zone; `fastForward` becomes `startAt`. | 2 tests that do not call `parse()`. |
| lib-recur | [lib-recur@5ba0c83](https://github.com/dmfs/lib-recur/blob/5ba0c83529d47e8020fa3b6411689841edca6242/src/test/java/org/dmfs/rfc5545/recur/InvalidRuleTest.java) | Apache-2.0 | 32 invalid | Regex over `InvalidRuleTest.java`. The lax-mode result is in `notes.laxMode`. | 1 exact duplicate. |
| php-rrule | [php-rrule@93a083d](https://github.com/rlanvin/php-rrule/blob/93a083db12dcb6f58e4840392a22e158ce96f1ff/tests/RRuleTest.php) | MIT | 66 invalid | PHP literal parser for `invalidRules` (option arrays become RRULE text) and `invalidRfcStrings` (lines trimmed). | 7 option values that are not text (PHP null, object, empty array). |
| pycalendar | [ccs-pycalendar@a12dd4e](https://github.com/apple/ccs-pycalendar/blob/a12dd4e1ce8822b022d4abf2cfe6cc93902ff03f/src/pycalendar/icalendar/tests/rrule_examples.json) | Apache-2.0 | 15 | JSON conversion. All rules are unbounded, so all cases are truncated. | 1 entry with the invalid DTSTART `20140140T120000`. |

Sources not imported:
- rrule.js, rrule-go and the php-rrule expansion tests: ports of the dateutil tests.
- python-recurring-ical-events: LGPL-3.0-or-later, and it expands with dateutil.
- rruleref: no licence.

## Licences

Each `data/<source>/` directory keeps the licence of its source; its `NOTICE` gives the details.
`libical`, `mozilla` and `ical.js` are under MPL-2.0. MPL-2.0 is a file-level copyleft, so changes to those files stay under MPL-2.0. The rest of the repository is a "Larger Work" (MPL-2.0 section 3.3).
`lib-recur`, `pycalendar` and `dateutil` include the Apache-2.0 text and a statement of changes.
The tools in `tools/` are part of this project and use its licence.

## Regenerate

Clone each source at its pinned commit. Pass the clone path to the tool. Do not run code from the clones.

```sh
git clone https://github.com/libical/libical.git && git -C libical checkout a298354d59a550afe63d44ee7760f2afea599a47
python3 -I tests/Corpus/tools/extract_libical.py libical
```

| Tool | Argument |
|---|---|
| `extract_rfc5545.py` | `rfc5545.txt` (https://www.rfc-editor.org/rfc/rfc5545.txt) |
| `extract_rfc7529.py` | `rfc7529.txt` (https://www.rfc-editor.org/rfc/rfc7529.txt) |
| `extract_libical.py` | libical clone |
| `extract_icalnet.py` | ical.net clone |
| `extract_dateutil.py` | dateutil clone (`--verbose` lists the skipped tests) |
| `extract_rust_rrule.py` | rust-rrule clone |
| `extract_mozilla.py` | directory with `test_recur.js` from the pinned revision (`raw-file` URL in the tool docstring) |
| `extract_icaljs.py` | ical.js clone |
| `extract_sabre.py` | vobject clone |
| `extract_librecur.py` | lib-recur clone |
| `extract_phprrule.py` | php-rrule clone |
| `extract_pycalendar.py` | ccs-pycalendar clone |

Each tool checks the pinned commit (or the file hash) and writes the same bytes on every run.
After a change, run `python3 -I tests/Corpus/tools/validate.py` and `python3 -I tests/Corpus/tools/verify_rfc5545.py`.
