# RRULE for PHP

Strictly typed iCalendar recurrence for PHP: [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545) `RRULE`, `RDATE`, `EXDATE` (and `EXRULE`) with all verified errata, and the [RFC 7529](https://www.rfc-editor.org/rfc/rfc7529) `RSCALE=GREGORIAN` / `SKIP` extension.

- 📐 **RFC compliant:** every ambiguity is decided from the RFC text, its verified errata and the JSCalendar 2.0 expansion algorithm, and [documented](#semantics)
- 🌍 **Correct time zones:** DST gaps and overlaps resolve as RFC 5545 section 3.3.5 requires (PHP's own `DateTime` does not)
- 🧷 **Typed:** generics tell PHPStan whether you get dates, floating date-times or instants; immutable values everywhere
- ⏩ **Fast queries:** `firstAfter()` skips ahead instead of iterating from the start; every rule is proven to terminate
- 🕸️ **Zero dependencies:** only PHP 8.1+
- 🧪 **Tested against the world:** ~950 cases from libical, Ical.Net, python-dateutil, Mozilla, ical.js, sabre/vobject, rust-rrule, lib-recur, php-rrule, pycalendar and the RFC examples, plus differential tests against libical 4, python-dateutil and an independent reference implementation
- 🔬 **PHPStan max level, 100 % coverage, mutation testing:** enforced in CI

## Installation

```sh
composer require shipmonk/rrule
```

## Quick start

```php
use ShipMonk\RRule\Recurrence;

$recurrence = Recurrence::fromString(<<<'ICS'
    DTSTART;TZID=Europe/Prague:20260130T090000
    RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1
    ICS);

// parsed text can have a date, floating or zoned start; this narrows the type for PHPStan
if (!$recurrence->isInstant()) {
    throw new LogicException('Expected a start with a time zone');
}

$next = $recurrence->firstAfter(new DateTimeImmutable()); // the last workday of the month, 09:00 Prague time

foreach ($recurrence->take(3) as $occurrence) {
    echo $occurrence->format('c'), "\n";
}
```

The same recurrence in code:

```php
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;

$rule = new RecurrenceRule(
    frequency: Frequency::Monthly,
    byDay: array_map(WeekdayNum::every(...), [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday]),
    bySetPos: [-1],
);

$recurrence = Recurrence::of(new DateTimeImmutable('2026-01-30 09:00', new DateTimeZone('Europe/Prague')), $rule);
echo $recurrence->toString();
// DTSTART;TZID=Europe/Prague:20260130T090000
// RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1
```

## Start types

The type of the start (DTSTART) decides the type of every occurrence. PHPStan knows it: `Recurrence::of($dateTimeImmutable)` is a `Recurrence<DateTimeImmutable>`, and its iteration, `first()`, `firstAfter()` and so on return `DateTimeImmutable`.

| Start | RFC 5545 value | Occurrences | UNTIL must be |
|---|---|---|---|
| `CalendarDate::of(2026, 1, 31)` | DATE (all-day) | `CalendarDate` | `Until::date()` |
| `FloatingDateTime::of(2026, 1, 31, 9)` | DATE-TIME without zone ("floating") | `FloatingDateTime` | `Until::floating()` |
| `new DateTimeImmutable('...', new DateTimeZone('UTC'))` | DATE-TIME in UTC | `DateTimeImmutable` in UTC | `Until::instant()` |
| `new DateTimeImmutable('...', new DateTimeZone('Europe/Prague'))` | DATE-TIME with TZID | `DateTimeImmutable` in that zone | `Until::instant()` |
| `Recurrence::inTimeZone(FloatingDateTime::of(2026, 3, 29, 2, 30), $prague)` | DATE-TIME with TZID, as written | `DateTimeImmutable` in that zone | `Until::instant()` |

`Recurrence::inTimeZone()` takes the start as a local time, the way `DTSTART;TZID=...` writes it, and the parser uses it. The difference matters only for a start that does not exist (inside a DST gap): the first occurrence moves forward (02:30 becomes 03:30), but the rules keep 02:30 for the other days. A `DateTimeImmutable` has already lost the written time.

A `DateTimeImmutable` start must use a named IANA zone or UTC: a fixed offset (`+02:00`) or an abbreviation (`CEST`) has no TZID, so it is rejected. PHP builds `new DateTimeZone('CET')` (also `EET`, `MET`, `WET`) as a fixed offset without DST, so such a start is rejected too; the parser reads `TZID=CET` as the IANA zone (`Europe/Brussels`).
`FloatingDateTime::atTimeZone()` and `CalendarDate::atStartOfDayIn()` convert to instants with the RFC rules for DST gaps and overlaps.

A parsed recurrence can have any start type. `isAllDay()`, `isFloating()` and `isInstant()` tell which, and narrow the type for PHPStan. A parameter that accepts every kind is `Recurrence<*>`. `getStartLocalTime()` returns the start as DTSTART writes it.

## Queries

All queries are lazy and start a new expansion, so a `Recurrence` is safe to share and to iterate many times.

```php
foreach ($recurrence as $index => $occurrence) {}      // all occurrences, ascending
$recurrence->first();                                   // or null
$recurrence->firstAfter($value, inclusive: false);      // skips ahead; does not iterate from the start
$recurrence->lastBefore($value, inclusive: false);
$recurrence->between($from, $to);                       // half-open [from, to), a Generator
$recurrence->take(10);                                  // list of the first 10
$recurrence->occursAt($value);
$recurrence->isFinite();                                // every rule has COUNT or UNTIL
```

For an instant recurrence, query values may be in any time zone. Occurrences have whole seconds; a query value with microseconds is compared exactly (`firstAfter(10:00:00.5)` does not return 10:00:00). A rule with `COUNT` is always expanded from the start, because the count depends on all earlier occurrences.

## Recurrence sets

```php
$recurrence = Recurrence::of($start, $rule, $anotherRule)   // several RRULEs are allowed
    ->withAddedDates($extraDate)                            // RDATE
    ->withExcludedDates($cancelledDate)                     // EXDATE
    ->withExcludedDays(CalendarDate::of(2026, 12, 24))      // EXDATE;VALUE=DATE for a DATE-TIME start
    ->withExclusionRules($exclusionRule)                    // EXRULE (RFC 2445)
    ->withDtStartPolicy(DtStartPolicy::Always);             // the default
```

The set is the start, the rules and the added dates, without the excluded dates, the excluded days and the occurrences of the exclusion rules. Equal instants count once.

Every `with*` method replaces its list. To add a value, pass the current ones too: `$recurrence->withAddedDates(...$recurrence->getAddedDates(), $newDate)`. The start, RDATE and EXDATE values must be in the years 1 to 9999, also in UTC, so that RFC 5545 text can express them.

## Parsing and serialization

```php
$rule = RecurrenceRule::fromString('FREQ=WEEKLY;BYDAY=MO,WE');      // the RECUR value
$recurrence = Recurrence::fromString($contentLines);                // DTSTART, RRULE, RDATE, EXDATE lines

$rule->toString();          // FREQ=WEEKLY;BYDAY=MO,WE
$recurrence->toString();    // content lines, folded at 75 octets, CRLF (or pass "\n")
```

The parser is strict by default: it accepts exactly what RFC 5545 and RFC 7529 allow (names are case-insensitive, as the RFC says). `RecurrenceRule::fromString($rule->toString())` equals `$rule`, and `Recurrence::fromString($recurrence->toString())` has the same occurrences as `$recurrence`, except:

- text with `EXRULE` parses only in lenient mode, because RFC 5545 removed `EXRULE`;
- `DtStartPolicy` is not part of the text;
- a zoned start in the second pass of a DST overlap (for example 02:30+01:00 on 2026-10-25 in Europe/Prague) has no text form, because `DTSTART;TZID` means the first pass. `toString()` then throws the unchecked `SerializationException`.

Store recurrences as `toString()` text, not with `serialize()`. `Recurrence::fromString()` drops the parse warnings; use `RecurrenceParser` to read them.

Real-world data breaks the RFC in known ways. `ParseMode::Lenient` accepts a closed list of such deviations and reports each one as a coded warning:

```php
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\RecurrenceParser;

$result = (new RecurrenceParser(ParseMode::Lenient))->parseRecurrence($googleCalendarExport);
$recurrence = $result->value;

foreach ($result->warnings as $warning) {
    // $warning->code is a ParseWarningCode, for example UntilValueTypeConverted
}
```

| Deviation | Seen in | Lenient handling |
|---|---|---|
| `UNTIL` value type differs from `DTSTART` (for example a DATE UNTIL with a zoned start) | Google Calendar, Office 365, libical test data | converted: a date means the end of that local day |
| `COUNT` together with `UNTIL`, or `COUNT=-1` | dateutil output, Office 365 | `UNTIL` kept, `COUNT` dropped |
| A rule part that the RFC forbids for the frequency (for example `BYYEARDAY` with `FREQ=MONTHLY`) | Google, Apple, dateutil | the part is dropped |
| `BYHOUR`/`BYMINUTE`/`BYSECOND` with a DATE start | older applications | dropped, as RFC 5545 says |
| Unknown or `X-` rule parts, duplicate parts, empty parts | various | ignored |
| `DTSTART` inside the `RRULE` value | rrule.js, old dateutil | used as the start |
| `EXRULE` | RFC 2445 producers | accepted |
| `SKIP` without `RSCALE` | | `RSCALE=GREGORIAN` assumed |
| Unknown properties (for example `X-` lines) | various | ignored |
| `TZID` with a UTC value (`DTSTART;TZID=Europe/Prague:20260101T090000Z`) | various | the TZID is ignored |
| `RDATE;VALUE=DATE` with a DATE-TIME start | various | converted to the start of each day |

Two warnings also come in strict mode, because the input is valid: `PeriodEndIgnored` (an `RDATE;VALUE=PERIOD` adds only the period start) and `DateExclusionForDateTimeStart` (`EXDATE;VALUE=DATE` with a DATE-TIME start excludes whole days). New warning codes and rule violations can come in minor versions, so do not match these enums without a default arm.

## Semantics

Where RFC 5545 is ambiguous, the library follows the [JSCalendar 2.0](https://datatracker.ietf.org/doc/draft-ietf-calext-jscalendarbis-22/) expansion algorithm (draft 22, section 4.3.3.1). That text comes from the same CalConnect authors and is meant to match the iCalendar RRULE.

| | Question | Decision | Source |
|---|---|---|---|
| S1 | Is a start that does not match the rule an occurrence? | Yes, and it counts for `COUNT`. `DtStartPolicy::OnlyIfMatching` gives the dateutil/libical behaviour. | RFC 5545 3.3.10 ("always counts as the first occurrence"), 3.8.5.1; JSCalendar |
| S2 | Invalid date (February 30, the 31st of a short month) | Omitted and not counted. With `SKIP=BACKWARD`/`FORWARD`, moved. `SKIP` does not adjust `BYYEARDAY` or `BYWEEKNO`. | RFC 5545 3.3.10, RFC 7529 4.1, JSCalendar |
| S3 | Local time in a DST gap | Moved with the offset before the gap (02:30 becomes 03:30), counted; later occurrences keep 02:30 | Verified erratum 4271, RFC 5545 3.3.5 |
| S4 | Local time in a DST overlap | The first occurrence | RFC 5545 3.3.5 |
| S4a | Start in a DST gap | The start moves forward (S3); the rules keep the written time; no occurrence comes before the start | RFC 5545 3.3.5, 3.8.5.1 |
| S5 | `HOURLY`/`MINUTELY`/`SECONDLY` across DST | Computed in wall-clock time, then S3/S4; equal instants count once | RFC 5545 3.3.10, 3.8.5.3; JSCalendar periods |
| S6 | `UNTIL` | Inclusive; the value type of the start (strict) | RFC 5545 3.3.10, erratum 4414 |
| S7 | `UNTIL` before the start | The rule has no occurrences; the start follows S1 | JSCalendar |
| S8 | `BYDAY=MO,1FR` | Every Monday and the first Friday | RFC 5545 grammar |
| S9 | `FREQ=YEARLY;BYMONTHDAY=29` (no `BYMONTH`) | In the month of the start | RFC 5545 3.3.10 (missing parts come from DTSTART), JSCalendar implied parts |
| S10 | `FREQ=YEARLY;BYWEEKNO=20` (no `BYDAY`) | On the weekday of the start | JSCalendar implied parts |
| S11 | Other missing parts | From the start, by the JSCalendar table | JSCalendar |
| S12 | Week numbers | Every date has exactly one week number; week 1 has at least 4 days of the year; weeks start on `WKST` | RFC 5545 3.3.10, JSCalendar |
| S13 | `INTERVAL` with `BYWEEKNO` | Counts calendar years (libical counts ISO week-numbering years) | JSCalendar yearly period |
| S14 | `BYSETPOS` | Selects from the whole period that contains the start, then earlier candidates are dropped; for every frequency | JSCalendar steps 1–4 |
| S15 | `BYDAY` ordinal with `YEARLY` | Within the month when the rule has `BYMONTH`, also when S9 implies it from the start (so `FREQ=YEARLY;BYMONTHDAY=1,2,3,4,5,6,7;BYDAY=1SU` from March is the first Sunday of March); within the year otherwise | JSCalendar (implied parts are added to the rule first), verified errata 3779 and 1913 |
| S16 | `BYHOUR` with a DATE start | Strict: rejected. Lenient: ignored. | RFC 5545 3.3.10 |
| S17 | Years | 1 to 9999 (proleptic Gregorian) | RFC 5545 DATE has four digits |
| S18 | `BYSECOND=60` | Accepted; it matches nothing (PHP has no leap seconds) | RFC 5545 3.3.10 |
| S19 | Non-Gregorian `RSCALE` | Rejected | RFC 7529 section 6 allows this |
| S20 | `SKIP` moves a date into the next or previous month | The date belongs to the set of the period that produced it; occurrences are in ascending order, and `COUNT` counts them in that order | RFC 7529 3.1, JSCalendar step 2.2 |

## Time zones

The library converts local times to instants itself, from `DateTimeZone::getTransitions()`. PHP's `new DateTimeImmutable($localTime, $zone)` returns the second occurrence for more than half of the DST overlaps in the world (for example 02:30 on 2026-10-25 in Europe/Prague), and the result of `setTime()` depends on the earlier state of the object. The resolver is checked against Python's `zoneinfo` (PEP 495, `fold=0`, which implements the RFC rule) for every zone and every transition between 1970 and 2037.

## Termination and limits

The Gregorian calendar repeats after 400 years (146 097 days, a multiple of 7). The expansion uses this to prove that a rule produces no more occurrences, so every query ends, also for rules like `FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30`. Rules that can never align (for example `FREQ=HOURLY;INTERVAL=2;BYHOUR=1` from midnight) are detected without iteration.

A query stops with `SearchLimitExceededException` after 1 000 000 expansion steps (change it with `withSearchLimit()`). The exception is unchecked; it means that the answer is unknown, not that there are no more occurrences. Only extremely sparse rules reach it.

## Exceptions

All exceptions implement `ShipMonk\RRule\Exception\RecurrenceException`.

| Exception | When |
|---|---|
| `ParseException` | the text is not valid (in the chosen mode); `$lineNumber` points to the line |
| `InvalidRecurrenceRuleException` | a rule breaks an RFC requirement; `$violation` says which |
| `InvalidRecurrenceException` | the start, the rules and the dates do not fit together (for example the UNTIL value type), a stored value is outside the years 1 to 9999, or the start zone is not supported |
| `InvalidValueException` | a date, time, count or ordinal is out of range |
| `InvalidArgumentException` (unchecked) | the calling code breaks a method contract: a value of another type than the start, a rule value outside its type (`INTERVAL` 0, `BYHOUR` 24), a search limit below 1, a negative `take()` limit |
| `SerializationException` (unchecked) | the recurrence has no RFC 5545 text form (see [Parsing and serialization](#parsing-and-serialization)) |
| `SearchLimitExceededException` (unchecked) | see [Termination and limits](#termination-and-limits) |

## Testing

- `tests/Corpus`: about 950 cases imported from the RFC 5545 and RFC 7529 examples, libical, Ical.Net, python-dateutil, Mozilla Calendar, ical.js, sabre/vobject, rust-rrule, lib-recur, php-rrule and pycalendar, each with its licence. A case where the source disagrees with this library is listed in `CorpusDeviations` with the reason; nothing is skipped silently.
- `tests/Reference`: a slow, literal implementation of the JSCalendar algorithm that shares no code with the engine, compared on 1 000 random rules. Its results are stored in `tests/Reference/data/naive-snapshot.json`, so the default suite (and mutation testing) runs the comparison in about one second. The reference suite checks the snapshot against a new naive expansion.
- `tests/Differential` (Docker): differential tests on 2 000 random rules each against libical 4 (through PHP FFI) and python-dateutil 4.0, and a check of the time zone resolver against Python `zoneinfo` (every zone, every transition from 1970 to 2037, about 147 000 local times). Every difference from libical or dateutil must fall in a documented category, or the reference implementation must side with this library; otherwise the test fails. These tests found bugs in libical, each pinned with its RFC result in `LibicalKnownBugsTest`: DAILY with BYSETPOS returns dates centuries later, sub-daily rules skip the rest of the first day, hour or minute, `BYWEEKNO=-53` selects the last week of every year, and SKIP drops the moved dates when BYDAY is present. They also found one bug in this library during development (a start in a DST gap moved the later occurrences).
- `tests/Type`: PHPStan type-inference tests for the public API.

## Development

```sh
composer check              # all checks below
composer check:cs           # coding standard
composer check:types        # PHPStan, level max
composer check:tests        # PHPUnit
composer check:coverage     # PHPUnit with coverage + coverage guard (100 % per method)
composer check:mutations    # Infection (needs PHP 8.3+)
composer check:reference    # naive reference results against the snapshot (minutes)
composer check:differential # differential tests against libical and python-dateutil (needs Docker)
composer update:reference-snapshot  # write the snapshot again after a change of NaiveExpander or ReferenceCase
```

## License

MIT. Test data under `tests/Corpus/data` keeps the licence of its source (MIT, BSD-3-Clause, Apache-2.0, MPL-2.0); see the `NOTICE` file in each directory. The test data is not part of the Composer package.
