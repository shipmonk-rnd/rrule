<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use ShipMonk\RRule\Exception\InvalidRecurrenceRuleException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Exception\RuleViolation;
use ShipMonk\RRule\Internal\RuleSerializer;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use function array_is_list;
use function is_int;
use function sprintf;

/**
 * A recurrence rule: the RFC 5545 RECUR value (section 3.3.10), with the RFC 7529 RSCALE and SKIP parts.
 *
 * The rule does not contain the start. Combine it with a start in {@see Recurrence}.
 * The constructor checks every RFC requirement that does not depend on the start. It adds no other limits.
 * An empty list means that the rule part is absent.
 *
 * @api
 */
final class RecurrenceRule
{

    /**
     * @param positive-int $interval
     * @param list<int<0, 60>> $bySecond
     * @param list<int<0, 59>> $byMinute
     * @param list<int<0, 23>> $byHour
     * @param list<WeekdayNum> $byDay
     * @param list<int<-31, -1>|int<1, 31>> $byMonthDay
     * @param list<int<-366, -1>|int<1, 366>> $byYearDay
     * @param list<int<-53, -1>|int<1, 53>> $byWeekNo
     * @param list<int<1, 12>> $byMonth
     * @param list<int<-366, -1>|int<1, 366>> $bySetPos
     * @throws InvalidRecurrenceRuleException
     */
    public function __construct(
        public readonly Frequency $frequency,
        public readonly int $interval = 1,
        public readonly Count|Until|null $end = null,
        public readonly array $bySecond = [],
        public readonly array $byMinute = [],
        public readonly array $byHour = [],
        public readonly array $byDay = [],
        public readonly array $byMonthDay = [],
        public readonly array $byYearDay = [],
        public readonly array $byWeekNo = [],
        public readonly array $byMonth = [],
        public readonly array $bySetPos = [],
        public readonly Weekday $weekStart = Weekday::Monday,
        public readonly ?CalendarScale $calendarScale = null,
        public readonly Skip $skip = Skip::Omit,
    )
    {
        if ($interval < 1) {
            throw new InvalidRecurrenceRuleException(RuleViolation::IntervalNotPositive, sprintf('INTERVAL must be a positive integer, %d given', $interval));
        }

        self::checkRange('BYSECOND', $bySecond, 0, 60, false);
        self::checkRange('BYMINUTE', $byMinute, 0, 59, false);
        self::checkRange('BYHOUR', $byHour, 0, 23, false);
        self::checkRange('BYMONTHDAY', $byMonthDay, -31, 31, true);
        self::checkRange('BYYEARDAY', $byYearDay, -366, 366, true);
        self::checkRange('BYWEEKNO', $byWeekNo, -53, 53, true);
        self::checkRange('BYMONTH', $byMonth, 1, 12, false);
        self::checkRange('BYSETPOS', $bySetPos, -366, 366, true);

        if (!array_is_list($byDay)) {
            throw new InvalidRecurrenceRuleException(RuleViolation::ValueOutOfRange, 'BYDAY must be a list');
        }

        foreach ($byDay as $weekdayNum) {
            if (!$weekdayNum instanceof WeekdayNum) { // @phpstan-ignore instanceof.alwaysTrue (runtime check for callers without static analysis)
                throw new InvalidRecurrenceRuleException(RuleViolation::ValueOutOfRange, 'BYDAY must contain WeekdayNum objects');
            }

            if ($weekdayNum->ordinal === null) {
                continue;
            }

            if ($frequency !== Frequency::Monthly && $frequency !== Frequency::Yearly) {
                throw new InvalidRecurrenceRuleException(
                    RuleViolation::ByDayOrdinalNotAllowed,
                    sprintf('BYDAY value %s has an ordinal, which is allowed only with FREQ=MONTHLY or FREQ=YEARLY', $weekdayNum->toString()),
                );
            }

            if ($byWeekNo !== []) {
                throw new InvalidRecurrenceRuleException(
                    RuleViolation::ByDayOrdinalNotAllowed,
                    sprintf('BYDAY value %s has an ordinal, which is not allowed together with BYWEEKNO', $weekdayNum->toString()),
                );
            }
        }

        if ($byWeekNo !== [] && $frequency !== Frequency::Yearly) {
            throw new InvalidRecurrenceRuleException(RuleViolation::ByWeekNoRequiresYearly, 'BYWEEKNO is allowed only with FREQ=YEARLY');
        }

        if ($byYearDay !== [] && ($frequency === Frequency::Daily || $frequency === Frequency::Weekly || $frequency === Frequency::Monthly)) {
            throw new InvalidRecurrenceRuleException(
                RuleViolation::ByYearDayNotAllowed,
                sprintf('BYYEARDAY is not allowed with FREQ=%s', $frequency->value),
            );
        }

        if ($byMonthDay !== [] && $frequency === Frequency::Weekly) {
            throw new InvalidRecurrenceRuleException(RuleViolation::ByMonthDayNotAllowedWithWeekly, 'BYMONTHDAY is not allowed with FREQ=WEEKLY');
        }

        if (
            $bySetPos !== []
            && $bySecond === []
            && $byMinute === []
            && $byHour === []
            && $byDay === []
            && $byMonthDay === []
            && $byYearDay === []
            && $byWeekNo === []
            && $byMonth === []
        ) {
            throw new InvalidRecurrenceRuleException(RuleViolation::BySetPosRequiresAnotherByPart, 'BYSETPOS must be used together with another BYxxx rule part');
        }

        if ($skip !== Skip::Omit && $calendarScale === null) {
            throw new InvalidRecurrenceRuleException(RuleViolation::SkipRequiresCalendarScale, 'SKIP requires RSCALE (RFC 7529 section 4.1)');
        }
    }

    /**
     * Parses an RFC 5545 RECUR value, for example "FREQ=WEEKLY;BYDAY=MO,WE". A leading "RRULE:" is accepted.
     *
     * @throws ParseException
     */
    public static function fromString(
        string $value,
        ParseMode $mode = ParseMode::Strict,
    ): self
    {
        return (new RecurrenceParser($mode))->parseRule($value)->value;
    }

    /**
     * The RFC 5545 RECUR value in canonical part order, without the "RRULE:" prefix.
     */
    public function toString(): string
    {
        return RuleSerializer::serializeRule($this);
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    /**
     * @param positive-int $interval
     * @throws InvalidRecurrenceRuleException
     */
    public function withInterval(int $interval): self
    {
        return $this->copy(interval: $interval);
    }

    /**
     * @throws InvalidRecurrenceRuleException
     */
    public function withEnd(Count|Until|null $end): self
    {
        return $this->copy(end: [$end]);
    }

    /**
     * @param list<int<0, 60>> $bySecond
     * @throws InvalidRecurrenceRuleException
     */
    public function withBySecond(array $bySecond): self
    {
        return $this->copy(bySecond: $bySecond);
    }

    /**
     * @param list<int<0, 59>> $byMinute
     * @throws InvalidRecurrenceRuleException
     */
    public function withByMinute(array $byMinute): self
    {
        return $this->copy(byMinute: $byMinute);
    }

    /**
     * @param list<int<0, 23>> $byHour
     * @throws InvalidRecurrenceRuleException
     */
    public function withByHour(array $byHour): self
    {
        return $this->copy(byHour: $byHour);
    }

    /**
     * @param list<WeekdayNum> $byDay
     * @throws InvalidRecurrenceRuleException
     */
    public function withByDay(array $byDay): self
    {
        return $this->copy(byDay: $byDay);
    }

    /**
     * @param list<int<-31, -1>|int<1, 31>> $byMonthDay
     * @throws InvalidRecurrenceRuleException
     */
    public function withByMonthDay(array $byMonthDay): self
    {
        return $this->copy(byMonthDay: $byMonthDay);
    }

    /**
     * @param list<int<-366, -1>|int<1, 366>> $byYearDay
     * @throws InvalidRecurrenceRuleException
     */
    public function withByYearDay(array $byYearDay): self
    {
        return $this->copy(byYearDay: $byYearDay);
    }

    /**
     * @param list<int<-53, -1>|int<1, 53>> $byWeekNo
     * @throws InvalidRecurrenceRuleException
     */
    public function withByWeekNo(array $byWeekNo): self
    {
        return $this->copy(byWeekNo: $byWeekNo);
    }

    /**
     * @param list<int<1, 12>> $byMonth
     * @throws InvalidRecurrenceRuleException
     */
    public function withByMonth(array $byMonth): self
    {
        return $this->copy(byMonth: $byMonth);
    }

    /**
     * @param list<int<-366, -1>|int<1, 366>> $bySetPos
     * @throws InvalidRecurrenceRuleException
     */
    public function withBySetPos(array $bySetPos): self
    {
        return $this->copy(bySetPos: $bySetPos);
    }

    /**
     * @throws InvalidRecurrenceRuleException
     */
    public function withWeekStart(Weekday $weekStart): self
    {
        return $this->copy(weekStart: $weekStart);
    }

    /**
     * Sets RSCALE=GREGORIAN and SKIP (RFC 7529). {@see Skip::Omit} removes both.
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withSkip(Skip $skip): self
    {
        return $this->copy(calendarScale: [$skip === Skip::Omit ? null : CalendarScale::Gregorian], skip: $skip);
    }

    /**
     * @param array{Count|Until|null}|null $end wrapped, so that null can mean "keep"
     * @param list<int<0, 60>>|null $bySecond
     * @param list<int<0, 59>>|null $byMinute
     * @param list<int<0, 23>>|null $byHour
     * @param list<WeekdayNum>|null $byDay
     * @param list<int<-31, -1>|int<1, 31>>|null $byMonthDay
     * @param list<int<-366, -1>|int<1, 366>>|null $byYearDay
     * @param list<int<-53, -1>|int<1, 53>>|null $byWeekNo
     * @param list<int<1, 12>>|null $byMonth
     * @param list<int<-366, -1>|int<1, 366>>|null $bySetPos
     * @param positive-int|null $interval
     * @param array{CalendarScale|null}|null $calendarScale wrapped, so that null can mean "keep"
     * @throws InvalidRecurrenceRuleException
     */
    private function copy(
        ?int $interval = null,
        ?array $end = null,
        ?array $bySecond = null,
        ?array $byMinute = null,
        ?array $byHour = null,
        ?array $byDay = null,
        ?array $byMonthDay = null,
        ?array $byYearDay = null,
        ?array $byWeekNo = null,
        ?array $byMonth = null,
        ?array $bySetPos = null,
        ?Weekday $weekStart = null,
        ?array $calendarScale = null,
        ?Skip $skip = null,
    ): self
    {
        return new self(
            frequency: $this->frequency,
            interval: $interval ?? $this->interval,
            end: $end === null ? $this->end : $end[0],
            bySecond: $bySecond ?? $this->bySecond,
            byMinute: $byMinute ?? $this->byMinute,
            byHour: $byHour ?? $this->byHour,
            byDay: $byDay ?? $this->byDay,
            byMonthDay: $byMonthDay ?? $this->byMonthDay,
            byYearDay: $byYearDay ?? $this->byYearDay,
            byWeekNo: $byWeekNo ?? $this->byWeekNo,
            byMonth: $byMonth ?? $this->byMonth,
            bySetPos: $bySetPos ?? $this->bySetPos,
            weekStart: $weekStart ?? $this->weekStart,
            calendarScale: $calendarScale === null ? $this->calendarScale : $calendarScale[0],
            skip: $skip ?? $this->skip,
        );
    }

    /**
     * @param array<mixed> $values
     * @throws InvalidRecurrenceRuleException
     */
    private static function checkRange(
        string $part,
        array $values,
        int $min,
        int $max,
        bool $nonZero,
    ): void
    {
        if (!array_is_list($values)) {
            throw new InvalidRecurrenceRuleException(RuleViolation::ValueOutOfRange, sprintf('%s must be a list', $part));
        }

        foreach ($values as $value) {
            if (!is_int($value) || $value < $min || $value > $max || ($nonZero && $value === 0)) {
                throw new InvalidRecurrenceRuleException(
                    RuleViolation::ValueOutOfRange,
                    sprintf('%s value %s is out of range (allowed: %d to %d%s)', $part, is_int($value) ? (string) $value : 'of a non-integer type', $min, $max, $nonZero ? ', except 0' : ''),
                );
            }
        }
    }

}
