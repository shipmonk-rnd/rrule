<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use DateTimeImmutable;
use Generator;
use IteratorAggregate;
use LogicException;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Internal\RecurrenceEngine;
use ShipMonk\RRule\Internal\RuleSerializer;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\Internal\Values\DateValues;
use ShipMonk\RRule\Internal\Values\FloatingValues;
use ShipMonk\RRule\Internal\Values\InstantValues;
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
 * - {@see CalendarDate}: all-day occurrences (DATE values),
 * - {@see FloatingDateTime}: wall-clock occurrences without a time zone,
 * - DateTimeImmutable in UTC or in a named zone: instants, returned in the zone of the start.
 *
 * The object is immutable. Every query starts a new expansion, so the object is safe to share and to iterate
 * more than once. Every query ends: a rule that stops producing occurrences is detected after one 400-year
 * Gregorian cycle at most. {@see SearchLimitExceededException} limits the work for extremely sparse rules.
 *
 * @template T of CalendarDate|FloatingDateTime|DateTimeImmutable
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
     * @param list<CalendarDate>   $excludedDays
     * @param list<RecurrenceRule> $exclusionRules
     * @param positive-int         $searchLimit
     */
    private function __construct(
        private readonly ValueConverter $values,
        private readonly CalendarDate|FloatingDateTime|DateTimeImmutable $start,
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
     * @return ($start is CalendarDate ? self<CalendarDate> : ($start is FloatingDateTime ? self<FloatingDateTime> : self<DateTimeImmutable>))
     *
     * @throws InvalidRecurrenceException
     */
    public static function of(
        CalendarDate|FloatingDateTime|DateTimeImmutable $start,
        RecurrenceRule ...$rules,
    ): self
    {
        if ($start instanceof CalendarDate) {
            return self::create(new DateValues(), $start, array_values($rules));
        }

        if ($start instanceof FloatingDateTime) {
            return self::create(new FloatingValues(), $start, array_values($rules));
        }

        try {
            $values = InstantValues::forStart($start);
        } catch (InvalidValueException $e) {
            throw new InvalidRecurrenceException('Invalid start: ' . $e->getMessage(), 0, $e);
        }

        return self::create($values, $values->accept($start, 'start'), array_values($rules));
    }

    /**
     * Parses DTSTART, RRULE, RDATE, EXDATE (and in lenient mode EXRULE) content lines.
     *
     * @return self<CalendarDate>|self<FloatingDateTime>|self<DateTimeImmutable>
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
     * Adds RDATE values. They must have the type of the start; instants may be in any zone.
     *
     * @param T ...$dates
     * @return self<T>
     *
     * @throws InvalidRecurrenceException
     */
    public function withAddedDates(CalendarDate|FloatingDateTime|DateTimeImmutable ...$dates): self
    {
        return $this->copy(addedDates: [...$this->addedDates, ...$this->acceptAll(array_values($dates), 'RDATE')]);
    }

    /**
     * Adds EXDATE values. They must have the type of the start; instants may be in any zone.
     *
     * @param T ...$dates
     * @return self<T>
     *
     * @throws InvalidRecurrenceException
     */
    public function withExcludedDates(CalendarDate|FloatingDateTime|DateTimeImmutable ...$dates): self
    {
        return $this->copy(excludedDates: [...$this->excludedDates, ...$this->acceptAll(array_values($dates), 'EXDATE')]);
    }

    /**
     * Excludes every occurrence on the given local dates (EXDATE;VALUE=DATE with a DATE-TIME start).
     * RFC 5545 does not define this combination; Outlook and Ical.Net give it this meaning (RFC 5545 erratum 6316).
     * For a date start, this is the same as {@see self::withExcludedDates()}.
     *
     * @return self<T>
     */
    public function withExcludedDays(CalendarDate ...$days): self
    {
        $excludedDates = $this->excludedDates;
        $excludedDays = $this->excludedDays;

        foreach ($days as $day) {
            $value = $this->values->dateAsValue($day);

            if ($value === null) {
                $excludedDays[] = $day;
            } else {
                $excludedDates[] = $value;
            }
        }

        return $this->copy(excludedDates: $excludedDates, excludedDays: $excludedDays);
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
     * {@see SearchLimitExceededException}.
     *
     * @param positive-int $searchLimit
     * @return self<T>
     */
    public function withSearchLimit(int $searchLimit): self
    {
        if ($searchLimit < 1) { // @phpstan-ignore smaller.alwaysFalse (runtime check for callers without static analysis)
            throw new LogicException('The search limit must be a positive integer');
        }

        return $this->copy(searchLimit: $searchLimit);
    }

    /**
     * @return T
     */
    public function getStart(): CalendarDate|FloatingDateTime|DateTimeImmutable
    {
        return $this->start;
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
     * @return list<CalendarDate>
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
        return $this->createEngine()->iterate(null, false, null);
    }

    /**
     * @return T|null
     */
    public function first(): CalendarDate|FloatingDateTime|DateTimeImmutable|null
    {
        foreach ($this->createEngine()->iterate(null, false, null) as $occurrence) {
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
     *
     * @throws InvalidRecurrenceException
     */
    public function firstAfter(
        CalendarDate|FloatingDateTime|DateTimeImmutable $after,
        bool $inclusive = false,
    ): CalendarDate|FloatingDateTime|DateTimeImmutable|null
    {
        foreach ($this->createEngine()->iterate($this->toPosition($after, 'after'), $inclusive, null) as $occurrence) {
            return $occurrence;
        }

        return null;
    }

    /**
     * The last occurrence before the given value (or at it, when inclusive).
     *
     * @param T $before
     * @return T|null
     *
     * @throws InvalidRecurrenceException
     */
    public function lastBefore(
        CalendarDate|FloatingDateTime|DateTimeImmutable $before,
        bool $inclusive = false,
    ): CalendarDate|FloatingDateTime|DateTimeImmutable|null
    {
        return $this->createEngine()->findLastBefore($this->toPosition($before, 'before'), $inclusive);
    }

    /**
     * Occurrences in the half-open range [from, to), in ascending order.
     *
     * @param T $from
     * @param T $to
     * @return Generator<int, T>
     *
     * @throws InvalidRecurrenceException
     */
    public function between(
        CalendarDate|FloatingDateTime|DateTimeImmutable $from,
        CalendarDate|FloatingDateTime|DateTimeImmutable $to,
    ): Generator
    {
        return $this->createEngine()->iterate($this->toPosition($from, 'from'), true, $this->toPosition($to, 'to'));
    }

    /**
     * The first occurrences, at most the given number.
     *
     * @param non-negative-int $limit
     * @return list<T>
     */
    public function take(int $limit): array
    {
        $result = [];

        if ($limit < 1) {
            return $result;
        }

        foreach ($this->createEngine()->iterate(null, false, null) as $occurrence) {
            $result[] = $occurrence;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param T $value
     *
     * @throws InvalidRecurrenceException
     */
    public function occursAt(CalendarDate|FloatingDateTime|DateTimeImmutable $value): bool
    {
        $position = $this->toPosition($value, 'value');

        return $this->createEngine()->iterate($position, true, $position + 1)->valid();
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
     */
    public function toString(string $lineBreak = "\r\n"): string
    {
        return RuleSerializer::serializeRecurrence($this, $lineBreak);
    }

    /**
     * @internal
     */
    public function getKind(): ValueKind
    {
        return $this->values->getKind();
    }

    /**
     * @param ValueConverter<S>    $values
     * @param S                    $start
     * @param list<RecurrenceRule> $rules
     * @return self<S>
     *
     * @template S of CalendarDate|FloatingDateTime|DateTimeImmutable
     *
     * @throws InvalidRecurrenceException
     */
    private static function create(
        ValueConverter $values,
        CalendarDate|FloatingDateTime|DateTimeImmutable $start,
        array $rules,
    ): self
    {
        self::checkRulesFitStart($rules, $values->getKind(), 'RRULE');

        return new self($values, $start, $rules, [], [], [], [], DtStartPolicy::Always, self::DEFAULT_SEARCH_LIMIT);
    }

    /**
     * @return RecurrenceEngine<T>
     */
    private function createEngine(): RecurrenceEngine
    {
        return new RecurrenceEngine(
            $this->values,
            $this->start,
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
     * @throws InvalidRecurrenceException
     */
    private function toPosition(
        CalendarDate|FloatingDateTime|DateTimeImmutable $value,
        string $name,
    ): int
    {
        return $this->values->toPosition($this->values->accept($value, $name));
    }

    /**
     * @param list<CalendarDate|FloatingDateTime|DateTimeImmutable> $values
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
            $accepted[] = $this->values->accept($value, $name);
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
            ValueKind::Date => $until instanceof CalendarDate,
            ValueKind::Floating => $until instanceof FloatingDateTime,
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
     * @param list<CalendarDate>|null   $excludedDays
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
