<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use IteratorAggregate;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Internal\RecurrenceEngine;
use ShipMonk\RRule\Internal\RuleSerializer;
use ShipMonk\RRule\Internal\TimeZoneResolver;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\Internal\Values\DateValues;
use ShipMonk\RRule\Internal\Values\InstantValues;
use ShipMonk\RRule\Internal\Values\LocalValues;
use ShipMonk\RRule\Internal\Values\ValueConverter;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use function array_values;
use function count;
use function sprintf;

/**
 * A recurrence set (RFC 5545 section 3.8.5): a start (DTSTART), rules (RRULE), added dates (RDATE),
 * excluded dates (EXDATE) and, for compatibility with RFC 2445, exclusion rules (EXRULE).
 *
 * The type of the start decides the type of every occurrence:
 * - {@see LocalDate}: all-day occurrences (DATE values),
 * - {@see LocalDateTime}: wall-clock occurrences without a time zone,
 * - DateTimeImmutable in UTC or in a named zone: instants, returned in the zone of the start.
 *
 * The object is immutable. Every query starts a new expansion, so the object is safe to share and to iterate
 * more than once. Every query ends: a rule that stops producing occurrences is detected after one 400-year
 * Gregorian cycle at most. {@see \ShipMonk\RRule\Exception\SearchLimitExceededException} limits the work for extremely sparse rules.
 *
 * Values have whole seconds: the start, RDATE and EXDATE values drop their microseconds. A query value with
 * microseconds is compared exactly, so `firstAfter(10:00:00.5)` does not return 10:00:00.
 *
 * @template T of LocalDate|LocalDateTime|DateTimeImmutable
 * @implements IteratorAggregate<int, T>
 *
 * @api
 */
final class Recurrence implements IteratorAggregate
{

    public const DEFAULT_SEARCH_LIMIT = 1_000_000;

    /**
     * @param ValueConverter<T>    $values
     * @param T                    $start
     * @param list<RecurrenceRule> $rules
     * @param list<T>              $addedDates
     * @param list<T>              $excludedDates
     * @param list<LocalDate>      $excludedDays
     * @param list<RecurrenceRule> $exclusionRules
     * @param positive-int         $searchLimit
     * @param int                  $startLocal     the start as local wall-clock seconds; for a zoned start, the time as written
     */
    private function __construct(
        private readonly ValueConverter $values,
        private readonly LocalDate|LocalDateTime|DateTimeImmutable $start,
        private readonly int $startLocal,
        private readonly array $rules,
        private readonly array $addedDates,
        private readonly array $excludedDates,
        private readonly array $excludedDays,
        private readonly array $exclusionRules,
        private readonly DtStartPolicy $dtStartPolicy,
        private readonly int $searchLimit,
    )
    {
    }

    /**
     * @return ($start is LocalDate ? self<LocalDate> : ($start is LocalDateTime ? self<LocalDateTime> : self<DateTimeImmutable>))
     *
     * @throws InvalidRecurrenceException
     */
    public static function of(
        LocalDate|LocalDateTime|DateTimeImmutable $start,
        RecurrenceRule ...$rules,
    ): self
    {
        if ($start instanceof LocalDate) {
            $dates = new DateValues();

            return self::create($dates, $start, array_values($rules), $dates->toPosition($start));
        }

        if ($start instanceof LocalDateTime) {
            return self::create(new LocalValues(), $start, array_values($rules), $start->toLocalSeconds());
        }

        $values = self::createInstantValues($start->getTimezone());
        $normalized = $values->acceptStored($start, 'start');

        return self::create($values, $normalized, array_values($rules), (new TimeZoneResolver($normalized->getTimezone()))->unixToLocal($normalized->getTimestamp()));
    }

    /**
     * A start written as a local wall-clock time in a time zone, as `DTSTART;TZID=...` writes it.
     *
     * The first occurrence is that time resolved by RFC 5545 section 3.3.5: a time in a DST gap moves forward,
     * a repeated time is its first occurrence. The rules keep the written time, so a start in a gap does not
     * move the later occurrences (02:30 stays 02:30 on the other days).
     *
     * @return self<DateTimeImmutable>
     *
     * @throws InvalidRecurrenceException
     */
    public static function inTimeZone(
        LocalDateTime $start,
        DateTimeZone $timeZone,
        RecurrenceRule ...$rules,
    ): self
    {
        $values = self::createInstantValues($timeZone);

        return self::create($values, $values->acceptStored($start->atTimeZone($timeZone), 'start'), array_values($rules), $start->toLocalSeconds());
    }

    /**
     * Parses DTSTART, RRULE, RDATE, EXDATE (and in lenient mode EXRULE) content lines.
     *
     * @return self<LocalDate>|self<LocalDateTime>|self<DateTimeImmutable>
     *
     * @throws ParseException
     */
    public static function fromString(
        string $text,
        ParseMode $mode = ParseMode::Strict,
    ): self
    {
        return (new RecurrenceParser($mode))->parseRecurrence($text)->value;
    }

    /**
     * Replaces the rules (RRULE).
     *
     * @return self<T>
     *
     * @throws InvalidRecurrenceException
     */
    public function withRules(RecurrenceRule ...$rules): self
    {
        $rules = array_values($rules);
        self::checkRulesFitStart($rules, $this->values->getKind(), 'RRULE');

        return $this->copy(rules: $rules);
    }

    /**
     * Replaces the RDATE values. They must have the type of the start; instants may be in any zone.
     * To add a value, pass the current ones too: `$r->withAddedDates(...$r->getAddedDates(), $new)`.
     *
     * @param T ...$dates
     * @return self<T>
     *
     * @throws InvalidRecurrenceException when a value is outside the years 1 to 9999
     */
    public function withAddedDates(LocalDate|LocalDateTime|DateTimeImmutable ...$dates): self
    {
        return $this->copy(addedDates: $this->acceptAll($dates, 'RDATE'));
    }

    /**
     * Replaces the EXDATE values. They must have the type of the start; instants may be in any zone.
     *
     * @param T ...$dates
     * @return self<T>
     *
     * @throws InvalidRecurrenceException when a value is outside the years 1 to 9999
     */
    public function withExcludedDates(LocalDate|LocalDateTime|DateTimeImmutable ...$dates): self
    {
        return $this->copy(excludedDates: $this->acceptAll($dates, 'EXDATE'));
    }

    /**
     * Replaces the excluded days: every occurrence on one of these local dates is removed (EXDATE;VALUE=DATE).
     * RFC 5545 does not define this for a DATE-TIME start; Outlook and Ical.Net give it this meaning (RFC 5545
     * erratum 6316). For a date start, an excluded day and an excluded date have the same effect.
     *
     * @return self<T>
     */
    public function withExcludedDays(LocalDate ...$days): self
    {
        return $this->copy(excludedDays: array_values($days));
    }

    /**
     * Replaces the EXRULE rules (RFC 2445; RFC 5545 deprecates them). An occurrence that an exclusion rule produces
     * is removed. The start counts for COUNT of an exclusion rule only when the rule produces it.
     *
     * @return self<T>
     *
     * @throws InvalidRecurrenceException
     */
    public function withExclusionRules(RecurrenceRule ...$rules): self
    {
        $rules = array_values($rules);
        self::checkRulesFitStart($rules, $this->values->getKind(), 'EXRULE');

        return $this->copy(exclusionRules: $rules);
    }

    /**
     * @return self<T>
     */
    public function withDtStartPolicy(DtStartPolicy $policy): self
    {
        return $this->copy(dtStartPolicy: $policy);
    }

    /**
     * Sets how many expansion steps (periods or jumps) one query may take before it throws
     * {@see \ShipMonk\RRule\Exception\SearchLimitExceededException}.
     *
     * @param positive-int $searchLimit
     * @return self<T>
     */
    public function withSearchLimit(int $searchLimit): self
    {
        if ($searchLimit < 1) { // @phpstan-ignore smaller.alwaysFalse (runtime check for callers without static analysis)
            throw new InvalidArgumentException('The search limit must be a positive integer');
        }

        return $this->copy(searchLimit: $searchLimit);
    }

    /**
     * @return positive-int
     */
    public function getSearchLimit(): int
    {
        return $this->searchLimit;
    }

    /**
     * True when the occurrences are dates (the start is a {@see LocalDate}).
     *
     * @phpstan-assert-if-true self<LocalDate> $this
     */
    public function isAllDay(): bool
    {
        return $this->start instanceof LocalDate;
    }

    /**
     * True when the occurrences are wall-clock date-times without a time zone (the start is a {@see LocalDateTime}).
     *
     * @phpstan-assert-if-true self<LocalDateTime> $this
     */
    public function isLocal(): bool
    {
        return $this->start instanceof LocalDateTime;
    }

    /**
     * True when the occurrences are instants (the start is a DateTimeImmutable in UTC or in a named zone).
     *
     * @phpstan-assert-if-true self<DateTimeImmutable> $this
     */
    public function isInstant(): bool
    {
        return $this->start instanceof DateTimeImmutable;
    }

    /**
     * @return T
     */
    public function getStart(): LocalDate|LocalDateTime|DateTimeImmutable
    {
        return $this->start;
    }

    /**
     * The start as a wall-clock time, as DTSTART writes it. For a zoned start, the rules repeat this time, which
     * can differ from the local time of {@see self::getStart()}: a start of 02:30 in a DST gap begins at 03:30,
     * and the later occurrences keep 02:30. For a date start, this is its midnight.
     */
    public function getStartLocalTime(): LocalDateTime
    {
        return LocalDateTime::fromLocalSeconds($this->startLocal);
    }

    /**
     * @return list<RecurrenceRule>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @return list<T>
     */
    public function getAddedDates(): array
    {
        return $this->addedDates;
    }

    /**
     * @return list<T>
     */
    public function getExcludedDates(): array
    {
        return $this->excludedDates;
    }

    /**
     * @return list<LocalDate>
     */
    public function getExcludedDays(): array
    {
        return $this->excludedDays;
    }

    /**
     * @return list<RecurrenceRule>
     */
    public function getExclusionRules(): array
    {
        return $this->exclusionRules;
    }

    public function getDtStartPolicy(): DtStartPolicy
    {
        return $this->dtStartPolicy;
    }

    /**
     * All occurrences in ascending order. The keys are 0, 1, 2 and so on.
     *
     * @return Generator<int, T>
     */
    public function getIterator(): Generator
    {
        return $this->createEngine()->iterate(null, null);
    }

    /**
     * @return T|null
     */
    public function first(): LocalDate|LocalDateTime|DateTimeImmutable|null
    {
        foreach ($this->createEngine()->iterate(null, null) as $occurrence) {
            return $occurrence;
        }

        return null;
    }

    /**
     * The first occurrence after the given value (or at it, when inclusive). For an instant recurrence, the value
     * may be in any time zone. The search skips ahead to the value instead of iterating from the start, unless
     * a rule has COUNT.
     *
     * @param T $after
     * @return T|null
     */
    public function firstAfter(
        LocalDate|LocalDateTime|DateTimeImmutable $after,
        bool $inclusive = false,
    ): LocalDate|LocalDateTime|DateTimeImmutable|null
    {
        $from = $inclusive ? $this->toPosition($after, 'after', roundUp: true) : $this->toPosition($after, 'after') + 1;

        foreach ($this->createEngine()->iterate($from, null) as $occurrence) {
            return $occurrence;
        }

        return null;
    }

    /**
     * The last occurrence before the given value (or at it, when inclusive).
     *
     * @param T $before
     * @return T|null
     */
    public function lastBefore(
        LocalDate|LocalDateTime|DateTimeImmutable $before,
        bool $inclusive = false,
    ): LocalDate|LocalDateTime|DateTimeImmutable|null
    {
        $end = $inclusive ? $this->toPosition($before, 'before') + 1 : $this->toPosition($before, 'before', roundUp: true);

        return $this->createEngine()->findLastBefore($end);
    }

    /**
     * Occurrences in the half-open range [from, to), in ascending order.
     *
     * @param T $from
     * @param T $to
     * @return Generator<int, T>
     */
    public function between(
        LocalDate|LocalDateTime|DateTimeImmutable $from,
        LocalDate|LocalDateTime|DateTimeImmutable $to,
    ): Generator
    {
        return $this->createEngine()->iterate($this->toPosition($from, 'from', roundUp: true), $this->toPosition($to, 'to', roundUp: true));
    }

    /**
     * The first occurrences, at most the given number.
     *
     * @param non-negative-int $limit
     * @return list<T>
     */
    public function take(int $limit): array
    {
        if ($limit < 0) { // @phpstan-ignore smaller.alwaysFalse (runtime check for callers without static analysis)
            throw new InvalidArgumentException('The limit must not be negative');
        }

        if ($limit === 0) {
            return [];
        }

        $result = [];

        foreach ($this->createEngine()->iterate(null, null) as $occurrence) {
            $result[] = $occurrence;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param T $value
     */
    public function occursAt(LocalDate|LocalDateTime|DateTimeImmutable $value): bool
    {
        $position = $this->toPosition($value, 'value');

        return !self::hasFraction($value) && $this->createEngine()->iterate($position, $position + 1)->valid();
    }

    /**
     * True when every rule has COUNT or UNTIL. A finite recurrence can still be empty.
     */
    public function isFinite(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->end === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The RFC 5545 content lines: DTSTART, RRULE, RDATE, EXDATE and EXRULE. Lines are folded at 75 octets.
     * The text keeps the meaning: parsing it gives the same occurrences. EXRULE text parses only in lenient mode.
     * It throws the unchecked {@see \ShipMonk\RRule\Exception\SerializationException} when DTSTART cannot express the start: a zoned start
     * in the second pass of a DST overlap.
     */
    public function toString(string $lineBreak = "\r\n"): string
    {
        return RuleSerializer::serializeRecurrence($this, $lineBreak);
    }

    /**
     * @throws InvalidRecurrenceException
     */
    private static function createInstantValues(DateTimeZone $timeZone): InstantValues
    {
        try {
            return InstantValues::forTimeZone($timeZone);
        } catch (InvalidValueException $e) {
            throw new InvalidRecurrenceException('Invalid start: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @param ValueConverter<S>    $values
     * @param S                    $start
     * @param list<RecurrenceRule> $rules
     * @return self<S>
     *
     * @template S of LocalDate|LocalDateTime|DateTimeImmutable
     *
     * @throws InvalidRecurrenceException
     */
    private static function create(
        ValueConverter $values,
        LocalDate|LocalDateTime|DateTimeImmutable $start,
        array $rules,
        int $startLocal,
    ): self
    {
        self::checkRulesFitStart($rules, $values->getKind(), 'RRULE');

        return new self($values, $start, $startLocal, $rules, [], [], [], [], DtStartPolicy::Always, self::DEFAULT_SEARCH_LIMIT);
    }

    /**
     * @return RecurrenceEngine<T>
     */
    private function createEngine(): RecurrenceEngine
    {
        return new RecurrenceEngine(
            $this->values,
            $this->start,
            $this->startLocal,
            $this->rules,
            $this->addedDates,
            $this->excludedDates,
            $this->excludedDays,
            $this->exclusionRules,
            $this->dtStartPolicy,
            $this->searchLimit,
        );
    }

    /**
     * Positions are whole seconds. With $roundUp, a value with microseconds maps to the next whole second.
     */
    private function toPosition(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
        bool $roundUp = false,
    ): int
    {
        $position = $this->values->toPosition($this->values->accept($value, $name));

        return $roundUp && self::hasFraction($value) ? $position + 1 : $position;
    }

    private static function hasFraction(LocalDate|LocalDateTime|DateTimeImmutable $value): bool
    {
        return $value instanceof DateTimeImmutable && $value->format('u') !== '000000';
    }

    /**
     * @param array<LocalDate|LocalDateTime|DateTimeImmutable> $values
     * @return list<T>
     *
     * @throws InvalidRecurrenceException
     */
    private function acceptAll(
        array $values,
        string $name,
    ): array
    {
        $accepted = [];

        foreach ($values as $value) {
            $accepted[] = $this->values->acceptStored($value, $name);
        }

        return $accepted;
    }

    /**
     * @param list<RecurrenceRule> $rules
     *
     * @throws InvalidRecurrenceException
     */
    private static function checkRulesFitStart(
        array $rules,
        ValueKind $kind,
        string $property,
    ): void
    {
        foreach ($rules as $rule) {
            self::checkRuleFitsStart($rule, $kind, $property);
        }
    }

    /**
     * @throws InvalidRecurrenceException
     */
    private static function checkRuleFitsStart(
        RecurrenceRule $rule,
        ValueKind $kind,
        string $property,
    ): void
    {
        if ($kind === ValueKind::Date) {
            if ($rule->bySecond !== [] || $rule->byMinute !== [] || $rule->byHour !== []) {
                throw new InvalidRecurrenceException(sprintf('%s %s: BYSECOND, BYMINUTE and BYHOUR must not be used with a DATE start (RFC 5545 section 3.3.10)', $property, $rule->toString()));
            }

            if ($rule->frequency === Frequency::Hourly || $rule->frequency === Frequency::Minutely || $rule->frequency === Frequency::Secondly) {
                throw new InvalidRecurrenceException(sprintf('%s %s: a DATE start cannot have a frequency shorter than a day', $property, $rule->toString()));
            }
        }

        if (!$rule->end instanceof Until) {
            return;
        }

        $until = $rule->end->value;
        $matches = match ($kind) {
            ValueKind::Date => $until instanceof LocalDate,
            ValueKind::Floating => $until instanceof LocalDateTime,
            ValueKind::Utc, ValueKind::Zoned => $until instanceof DateTimeImmutable,
        };

        if (!$matches) {
            throw new InvalidRecurrenceException(sprintf(
                '%s %s: UNTIL must be %s for this start (RFC 5545 section 3.3.10)',
                $property,
                $rule->toString(),
                match ($kind) {
                    ValueKind::Date => 'a DATE',
                    ValueKind::Floating => 'a floating DATE-TIME',
                    ValueKind::Utc, ValueKind::Zoned => 'a UTC DATE-TIME',
                },
            ));
        }
    }

    /**
     * @param list<RecurrenceRule>|null $rules
     * @param list<T>|null              $addedDates
     * @param list<T>|null              $excludedDates
     * @param list<LocalDate>|null      $excludedDays
     * @param list<RecurrenceRule>|null $exclusionRules
     * @param positive-int|null         $searchLimit
     * @return self<T>
     */
    private function copy(
        ?array $rules = null,
        ?array $addedDates = null,
        ?array $excludedDates = null,
        ?array $excludedDays = null,
        ?array $exclusionRules = null,
        ?DtStartPolicy $dtStartPolicy = null,
        ?int $searchLimit = null,
    ): self
    {
        return new self(
            $this->values,
            $this->start,
            $this->startLocal,
            $rules ?? $this->rules,
            $addedDates ?? $this->addedDates,
            $excludedDates ?? $this->excludedDates,
            $excludedDays ?? $this->excludedDays,
            $exclusionRules ?? $this->exclusionRules,
            $dtStartPolicy ?? $this->dtStartPolicy,
            $searchLimit ?? $this->searchLimit,
        );
    }

}
