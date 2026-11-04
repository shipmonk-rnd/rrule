<?php declare(strict_types = 1);

namespace ShipMonk\RRule;

use LogicException;
use ShipMonk\RRule\Exception\InvalidArgumentException;
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
     * @param positive-int                    $interval
     * @param list<int<0, 60>>                $bySecond
     * @param list<int<0, 59>>                $byMinute
     * @param list<int<0, 23>>                $byHour
     * @param list<WeekdayNum>                $byDay
     * @param list<int<-31, -1>|int<1, 31>>   $byMonthDay
     * @param list<int<-366, -1>|int<1, 366>> $byYearDay
     * @param list<int<-53, -1>|int<1, 53>>   $byWeekNo
     * @param list<int<1, 12>>                $byMonth
     * @param list<int<-366, -1>|int<1, 366>> $bySetPos
     *
     * @throws InvalidRecurrenceRuleException when the parts do not fit together (RFC 5545 section 3.3.10, RFC 7529);
     *                                        a value outside its type throws the unchecked InvalidArgumentException
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
        if ($interval < 1) { // @phpstan-ignore smaller.alwaysFalse (runtime check for callers without static analysis)
            throw new InvalidArgumentException(sprintf('INTERVAL must be a positive integer, %d given', $interval));
        }

        if (!array_is_list($bySecond)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYSECOND must be a list');
        }

        foreach ($bySecond as $second) {
            if (!is_int($second) || $second < 0 || $second > 60) { // @phpstan-ignore function.alreadyNarrowedType, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYSECOND', $second, '0 to 60'));
            }
        }

        if (!array_is_list($byMinute)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYMINUTE must be a list');
        }

        foreach ($byMinute as $minute) {
            if (!is_int($minute) || $minute < 0 || $minute > 59) { // @phpstan-ignore function.alreadyNarrowedType, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYMINUTE', $minute, '0 to 59'));
            }
        }

        if (!array_is_list($byHour)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYHOUR must be a list');
        }

        foreach ($byHour as $hour) {
            if (!is_int($hour) || $hour < 0 || $hour > 23) { // @phpstan-ignore function.alreadyNarrowedType, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYHOUR', $hour, '0 to 23'));
            }
        }

        if (!array_is_list($byMonthDay)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYMONTHDAY must be a list');
        }

        foreach ($byMonthDay as $monthDay) {
            if (!is_int($monthDay) || $monthDay === 0 || $monthDay < -31 || $monthDay > 31) { // @phpstan-ignore function.alreadyNarrowedType, identical.alwaysFalse, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYMONTHDAY', $monthDay, '-31 to 31, except 0'));
            }
        }

        if (!array_is_list($byYearDay)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYYEARDAY must be a list');
        }

        foreach ($byYearDay as $yearDay) {
            if (!is_int($yearDay) || $yearDay === 0 || $yearDay < -366 || $yearDay > 366) { // @phpstan-ignore function.alreadyNarrowedType, identical.alwaysFalse, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYYEARDAY', $yearDay, '-366 to 366, except 0'));
            }
        }

        if (!array_is_list($byWeekNo)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYWEEKNO must be a list');
        }

        foreach ($byWeekNo as $weekNo) {
            if (!is_int($weekNo) || $weekNo === 0 || $weekNo < -53 || $weekNo > 53) { // @phpstan-ignore function.alreadyNarrowedType, identical.alwaysFalse, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYWEEKNO', $weekNo, '-53 to 53, except 0'));
            }
        }

        if (!array_is_list($byMonth)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYMONTH must be a list');
        }

        foreach ($byMonth as $month) {
            if (!is_int($month) || $month < 1 || $month > 12) { // @phpstan-ignore function.alreadyNarrowedType, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYMONTH', $month, '1 to 12'));
            }
        }

        if (!array_is_list($bySetPos)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYSETPOS must be a list');
        }

        foreach ($bySetPos as $setPosition) {
            if (!is_int($setPosition) || $setPosition === 0 || $setPosition < -366 || $setPosition > 366) { // @phpstan-ignore function.alreadyNarrowedType, identical.alwaysFalse, smaller.alwaysFalse, greater.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse, booleanOr.alwaysFalse (runtime check for callers without static analysis)
                throw new InvalidArgumentException(self::describeOutOfRange('BYSETPOS', $setPosition, '-366 to 366, except 0'));
            }
        }

        if (!array_is_list($byDay)) { // @phpstan-ignore function.alreadyNarrowedType (runtime check for callers without static analysis)
            throw new InvalidArgumentException('BYDAY must be a list');
        }

        foreach ($byDay as $weekdayNum) {
            if (!$weekdayNum instanceof WeekdayNum) { // @phpstan-ignore instanceof.alwaysTrue (runtime check for callers without static analysis)
                throw new InvalidArgumentException('BYDAY must contain WeekdayNum objects');
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
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withInterval(int $interval): self
    {
        return $this->copy(interval: $interval);
    }

    public function withEnd(Count|Until|null $end): self
    {
        try {
            return $this->copy(end: [$end]);
        } catch (InvalidRecurrenceRuleException $e) {
            throw new LogicException('Changing the end cannot make a valid rule invalid', previous: $e);
        }
    }

    /**
     * @param list<int<0, 60>> $bySecond
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withBySecond(array $bySecond): self
    {
        return $this->copy(bySecond: $bySecond);
    }

    /**
     * @param list<int<0, 59>> $byMinute
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withByMinute(array $byMinute): self
    {
        return $this->copy(byMinute: $byMinute);
    }

    /**
     * @param list<int<0, 23>> $byHour
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withByHour(array $byHour): self
    {
        return $this->copy(byHour: $byHour);
    }

    /**
     * @param list<WeekdayNum> $byDay
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withByDay(array $byDay): self
    {
        return $this->copy(byDay: $byDay);
    }

    /**
     * @param list<int<-31, -1>|int<1, 31>> $byMonthDay
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withByMonthDay(array $byMonthDay): self
    {
        return $this->copy(byMonthDay: $byMonthDay);
    }

    /**
     * @param list<int<-366, -1>|int<1, 366>> $byYearDay
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withByYearDay(array $byYearDay): self
    {
        return $this->copy(byYearDay: $byYearDay);
    }

    /**
     * @param list<int<-53, -1>|int<1, 53>> $byWeekNo
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withByWeekNo(array $byWeekNo): self
    {
        return $this->copy(byWeekNo: $byWeekNo);
    }

    /**
     * @param list<int<1, 12>> $byMonth
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withByMonth(array $byMonth): self
    {
        return $this->copy(byMonth: $byMonth);
    }

    /**
     * @param list<int<-366, -1>|int<1, 366>> $bySetPos
     *
     * @throws InvalidRecurrenceRuleException
     */
    public function withBySetPos(array $bySetPos): self
    {
        return $this->copy(bySetPos: $bySetPos);
    }

    public function withWeekStart(Weekday $weekStart): self
    {
        try {
            return $this->copy(weekStart: $weekStart);
        } catch (InvalidRecurrenceRuleException $e) {
            throw new LogicException('Changing WKST cannot make a valid rule invalid', previous: $e);
        }
    }

    /**
     * Sets RSCALE=GREGORIAN and SKIP (RFC 7529). {@see Skip::Omit} removes both.
     */
    public function withSkip(Skip $skip): self
    {
        try {
            return $this->copy(calendarScale: [$skip === Skip::Omit ? null : CalendarScale::Gregorian], skip: $skip);
        } catch (InvalidRecurrenceRuleException $e) {
            throw new LogicException('Changing SKIP cannot make a valid rule invalid', previous: $e);
        }
    }

    /**
     * @param array{Count|Until|null}|null         $end           wrapped, so that null can mean "keep"
     * @param list<int<0, 60>>|null                $bySecond
     * @param list<int<0, 59>>|null                $byMinute
     * @param list<int<0, 23>>|null                $byHour
     * @param list<WeekdayNum>|null                $byDay
     * @param list<int<-31, -1>|int<1, 31>>|null   $byMonthDay
     * @param list<int<-366, -1>|int<1, 366>>|null $byYearDay
     * @param list<int<-53, -1>|int<1, 53>>|null   $byWeekNo
     * @param list<int<1, 12>>|null                $byMonth
     * @param list<int<-366, -1>|int<1, 366>>|null $bySetPos
     * @param positive-int|null                    $interval
     * @param array{CalendarScale|null}|null       $calendarScale wrapped, so that null can mean "keep"
     *
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

    private static function describeOutOfRange(
        string $part,
        mixed $value,
        string $allowed,
    ): string
    {
        return sprintf('%s value %s is out of range (allowed: %s)', $part, is_int($value) ? $value : 'of a non-integer type', $allowed);
    }

}
