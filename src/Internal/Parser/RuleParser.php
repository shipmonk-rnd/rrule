<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

use DateTimeImmutable;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\CalendarScale;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\Exception\InvalidRecurrenceRuleException;
use ShipMonk\RRule\Exception\InvalidValueException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Internal\Civil;
use ShipMonk\RRule\Internal\RuleSerializer;
use ShipMonk\RRule\Parser\ParseWarningCode;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_values;
use function explode;
use function in_array;
use function ltrim;
use function preg_match;
use function sprintf;
use function str_contains;
use function strcmp;
use function strlen;
use function strpos;
use function substr;
use const PHP_INT_MAX;

/**
 * Parses the RECUR value (RFC 5545 section 3.3.10, RFC 7529 section 4.1) in two steps:
 * {@see self::split()} reads the part names, {@see self::build()} parses the values and needs the start.
 *
 * @internal
 */
final class RuleParser
{

    private const PART_NAMES = [
        'FREQ',
        'UNTIL',
        'COUNT',
        'INTERVAL',
        'BYSECOND',
        'BYMINUTE',
        'BYHOUR',
        'BYDAY',
        'BYMONTHDAY',
        'BYYEARDAY',
        'BYWEEKNO',
        'BYMONTH',
        'BYSETPOS',
        'WKST',
        'RSCALE',
        'SKIP',
    ];

    public function __construct(
        private readonly ParseContext $context,
    )
    {
    }

    /**
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    public function split(
        string $value,
        ?int $lineNumber,
    ): RawRule
    {
        if ($value === '') {
            throw ParseErrors::create('The rule is empty', $lineNumber);
        }

        $parts = [];
        $start = null;

        foreach (explode(';', $value) as $part) {
            if ($part === '') {
                $this->context->deviate(
                    ParseWarningCode::EmptyRulePartIgnored,
                    sprintf('The rule "%s" contains an empty part', $value),
                    sprintf('Ignored an empty part of the rule "%s"', $value),
                    $lineNumber,
                );
                continue;
            }

            $separator = strpos($part, '=');

            if ($separator === false) {
                throw ParseErrors::create(sprintf('Invalid rule part "%s": expected NAME=VALUE', $part), $lineNumber);
            }

            $name = substr($part, 0, $separator);
            $partValue = substr($part, $separator + 1);

            if (preg_match('~^[A-Za-z0-9-]+\z~', $name) !== 1) {
                throw ParseErrors::create(sprintf('Invalid rule part "%s": the name is not valid', $part), $lineNumber);
            }

            $name = ValueParser::upper($name);
            $isStart = $name === 'DTSTART' && $this->context->isLenient();

            if (!$isStart && !in_array($name, self::PART_NAMES, true)) {
                $this->context->deviate(
                    ParseWarningCode::UnknownRulePartIgnored,
                    sprintf('Unknown rule part "%s"', $part),
                    sprintf('Ignored the unknown rule part "%s"', $part),
                    $lineNumber,
                );
                continue;
            }

            if (isset($parts[$name]) || ($isStart && $start !== null)) {
                $this->context->deviate(
                    ParseWarningCode::DuplicateRulePartIgnored,
                    sprintf('The rule part %s occurs more than once', $name),
                    sprintf('Ignored the duplicate rule part "%s"', $part),
                    $lineNumber,
                );
                continue;
            }

            if ($partValue === '') {
                throw ParseErrors::create(sprintf('The rule part %s has an empty value', $name), $lineNumber);
            }

            if ($isStart) {
                $start = $partValue;
            } else {
                $parts[$name] = $partValue;
            }
        }

        return new RawRule($value, $parts, $start, $lineNumber);
    }

    /**
     * @param CalendarDate|FloatingDateTime|DateTimeImmutable|null $start null for a rule without a recurrence
     *
     * @throws ParseException
     */
    public function build(
        RawRule $rule,
        CalendarDate|FloatingDateTime|DateTimeImmutable|null $start,
    ): RecurrenceRule
    {
        $parts = $rule->parts;
        $lineNumber = $rule->lineNumber;

        if (!isset($parts['FREQ'])) {
            throw ParseErrors::create(sprintf('Invalid rule "%s": FREQ is required', $rule->source), $lineNumber);
        }

        $frequency = Frequency::tryFrom(ValueParser::upper($parts['FREQ'])) ?? throw ParseErrors::create(
            sprintf('Invalid FREQ value "%s" (allowed: SECONDLY, MINUTELY, HOURLY, DAILY, WEEKLY, MONTHLY, YEARLY)', $parts['FREQ']),
            $lineNumber,
        );
        $interval = isset($parts['INTERVAL']) ? $this->parseInterval($parts['INTERVAL'], $lineNumber) : 1;
        $count = isset($parts['COUNT']) ? $this->parseCount($parts['COUNT'], $lineNumber) : null;
        $until = isset($parts['UNTIL']) ? $this->parseUntil($parts['UNTIL'], $start, $lineNumber) : null;

        if ($count !== null && $until !== null) {
            $this->context->deviate(
                ParseWarningCode::CountWithUntilCountIgnored,
                sprintf('Invalid rule "%s": COUNT and UNTIL must not occur in the same rule (RFC 5545 section 3.3.10)', $rule->source),
                sprintf('Ignored COUNT=%d, because the rule also has UNTIL', $count->value),
                $lineNumber,
            );
        }

        $bySecond = $this->parseBySecond($parts['BYSECOND'] ?? null, $lineNumber);
        $byMinute = $this->parseByMinute($parts['BYMINUTE'] ?? null, $lineNumber);
        $byHour = $this->parseByHour($parts['BYHOUR'] ?? null, $lineNumber);
        $byDay = $this->parseByDay($parts['BYDAY'] ?? null, $lineNumber);
        $byMonthDay = $this->parseByMonthDay($parts['BYMONTHDAY'] ?? null, $lineNumber);
        $byYearDay = $this->parseYearDays('BYYEARDAY', $parts['BYYEARDAY'] ?? null, $lineNumber);
        $byWeekNo = $this->parseByWeekNo($parts['BYWEEKNO'] ?? null, $lineNumber);
        $byMonth = $this->parseByMonth($parts['BYMONTH'] ?? null, $lineNumber);
        $bySetPos = $this->parseYearDays('BYSETPOS', $parts['BYSETPOS'] ?? null, $lineNumber);

        $weekStart = isset($parts['WKST'])
            ? Weekday::tryFrom(ValueParser::upper($parts['WKST'])) ?? throw ParseErrors::create(
                sprintf('Invalid WKST value "%s" (allowed: MO, TU, WE, TH, FR, SA, SU)', $parts['WKST']),
                $lineNumber,
            )
            : Weekday::Monday;
        $calendarScale = isset($parts['RSCALE'])
            ? CalendarScale::tryFrom(ValueParser::upper($parts['RSCALE'])) ?? throw ParseErrors::create(
                sprintf('Invalid RSCALE value "%s": the calendar scale is not supported (only GREGORIAN is)', $parts['RSCALE']),
                $lineNumber,
            )
            : null;
        $skip = isset($parts['SKIP'])
            ? Skip::tryFrom(ValueParser::upper($parts['SKIP'])) ?? throw ParseErrors::create(
                sprintf('Invalid SKIP value "%s" (allowed: OMIT, BACKWARD, FORWARD)', $parts['SKIP']),
                $lineNumber,
            )
            : Skip::Omit;

        if (isset($parts['SKIP']) && $calendarScale === null) {
            $this->context->deviate(
                ParseWarningCode::SkipWithoutCalendarScale,
                sprintf('Invalid rule "%s": SKIP requires RSCALE (RFC 7529 section 4.1)', $rule->source),
                'SKIP requires RSCALE: assumed RSCALE=GREGORIAN',
                $lineNumber,
            );
            $calendarScale = CalendarScale::Gregorian;
        }

        if ($this->context->isLenient()) {
            if ($start instanceof CalendarDate) {
                $reason = 'RFC 5545 section 3.3.10 says that it must be ignored with a DATE start';
                $bySecond = $this->ignorePart($bySecond, ParseWarningCode::TimePartsIgnoredForDateStart, 'BYSECOND', $reason, $rule);
                $byMinute = $this->ignorePart($byMinute, ParseWarningCode::TimePartsIgnoredForDateStart, 'BYMINUTE', $reason, $rule);
                $byHour = $this->ignorePart($byHour, ParseWarningCode::TimePartsIgnoredForDateStart, 'BYHOUR', $reason, $rule);
            }

            if ($frequency !== Frequency::Yearly) {
                $byWeekNo = $this->ignorePart($byWeekNo, ParseWarningCode::ForbiddenRulePartIgnored, 'BYWEEKNO', 'BYWEEKNO is allowed only with FREQ=YEARLY', $rule);
            }

            if (in_array($frequency, [Frequency::Daily, Frequency::Weekly, Frequency::Monthly], true)) {
                $byYearDay = $this->ignorePart($byYearDay, ParseWarningCode::ForbiddenRulePartIgnored, 'BYYEARDAY', sprintf('BYYEARDAY is not allowed with FREQ=%s', $frequency->value), $rule);
            }

            if ($frequency === Frequency::Weekly) {
                $byMonthDay = $this->ignorePart($byMonthDay, ParseWarningCode::ForbiddenRulePartIgnored, 'BYMONTHDAY', 'BYMONTHDAY is not allowed with FREQ=WEEKLY', $rule);
            }

            $byDay = $this->ignoreOrdinals($byDay, $frequency, $byWeekNo, $rule);

            if ([...$bySecond, ...$byMinute, ...$byHour, ...$byDay, ...$byMonthDay, ...$byYearDay, ...$byWeekNo, ...$byMonth] === []) {
                $bySetPos = $this->ignorePart($bySetPos, ParseWarningCode::ForbiddenRulePartIgnored, 'BYSETPOS', 'BYSETPOS must be used together with another BYxxx rule part', $rule);
            }
        }

        try {
            return new RecurrenceRule(
                frequency: $frequency,
                interval: $interval,
                end: $until ?? $count,
                bySecond: $bySecond,
                byMinute: $byMinute,
                byHour: $byHour,
                byDay: $byDay,
                byMonthDay: $byMonthDay,
                byYearDay: $byYearDay,
                byWeekNo: $byWeekNo,
                byMonth: $byMonth,
                bySetPos: $bySetPos,
                weekStart: $weekStart,
                calendarScale: $calendarScale,
                skip: $skip,
            );
        } catch (InvalidRecurrenceRuleException $e) {
            throw ParseErrors::create(sprintf('Invalid rule "%s": %s', $rule->source, $e->getMessage()), $lineNumber, $e);
        }
    }

    /**
     * @param positive-int|null $lineNumber
     * @return positive-int
     *
     * @throws ParseException
     */
    private function parseInterval(
        string $value,
        ?int $lineNumber,
    ): int
    {
        $interval = $this->parseInteger('INTERVAL', $value, $value, '', $lineNumber);

        if ($interval < 1) {
            throw ParseErrors::create(sprintf('Invalid INTERVAL value "%s": INTERVAL must be a positive integer', $value), $lineNumber);
        }

        return $interval;
    }

    /**
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    private function parseCount(
        string $value,
        ?int $lineNumber,
    ): ?Count
    {
        $count = $this->parseInteger('COUNT', $value, $value, '-', $lineNumber);

        if ($count < 1 && $this->context->isLenient()) {
            $this->context->warn(ParseWarningCode::InvalidCountIgnored, sprintf('Ignored COUNT=%s: COUNT must be a positive integer', $value), $lineNumber);

            return null;
        }

        try {
            return Count::of($count);
        } catch (InvalidValueException $e) {
            throw ParseErrors::create(sprintf('Invalid COUNT value "%s": %s', $value, $e->getMessage()), $lineNumber, $e);
        }
    }

    /**
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    private function parseUntil(
        string $value,
        CalendarDate|FloatingDateTime|DateTimeImmutable|null $start,
        ?int $lineNumber,
    ): Until
    {
        $parsed = ValueParser::parseDateOrDateTime($value, 'UNTIL', $lineNumber);
        $converted = $start !== null && $this->context->isLenient() ? $this->convertUntil($parsed, $start) : null;
        $until = $converted ?? $parsed;

        if ($until instanceof CalendarDate) {
            $result = Until::date($until);
        } elseif ($until instanceof FloatingDateTime) {
            $result = Until::floating($until);
        } else {
            try {
                $result = Until::instant($until);
            } catch (InvalidValueException $e) {
                throw ParseErrors::create(sprintf('Invalid UNTIL value "%s": %s', $value, $e->getMessage()), $lineNumber, $e);
            }
        }

        if ($converted !== null) {
            $this->context->warn(
                ParseWarningCode::UntilValueTypeConverted,
                sprintf('Converted UNTIL=%s to UNTIL=%s, because DTSTART has a different value type', $value, RuleSerializer::formatUntil($result)),
                $lineNumber,
            );
        }

        return $result;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<WeekdayNum>
     *
     * @throws ParseException
     */
    private function parseByDay(
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        $byDay = [];

        foreach ($this->splitList('BYDAY', $value, $lineNumber) as $item) {
            $weekday = preg_match('~^([+-]?\d+)?([A-Za-z]{2})\z~', $item, $matches) === 1
                ? Weekday::tryFrom(ValueParser::upper($matches[2]))
                : null;

            if ($weekday === null) {
                throw ParseErrors::create(sprintf('Invalid BYDAY value "%s": expected a weekday (MO, TU, WE, TH, FR, SA, SU) with an optional ordinal', $item), $lineNumber);
            }

            $ordinal = $matches[1] === '' ? null : $this->parseInteger('BYDAY', $item, $matches[1], '+-', $lineNumber);

            try {
                $byDay[] = WeekdayNum::of($weekday, $ordinal);
            } catch (InvalidValueException $e) {
                throw ParseErrors::create(sprintf('Invalid BYDAY value "%s": %s', $item, $e->getMessage()), $lineNumber, $e);
            }
        }

        return $byDay;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<int<0, 60>>
     *
     * @throws ParseException
     */
    private function parseBySecond(
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        $numbers = [];

        foreach ($this->parseIntegers('BYSECOND', $value, '', $lineNumber) as [$item, $number]) {
            $numbers[] = $number >= 0 && $number <= 60 ? $number : throw self::outOfRange('BYSECOND', $item, 'allowed values are 0 to 60', $lineNumber);
        }

        return $numbers;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<int<0, 59>>
     *
     * @throws ParseException
     */
    private function parseByMinute(
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        $numbers = [];

        foreach ($this->parseIntegers('BYMINUTE', $value, '', $lineNumber) as [$item, $number]) {
            $numbers[] = $number >= 0 && $number <= 59 ? $number : throw self::outOfRange('BYMINUTE', $item, 'allowed values are 0 to 59', $lineNumber);
        }

        return $numbers;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<int<0, 23>>
     *
     * @throws ParseException
     */
    private function parseByHour(
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        $numbers = [];

        foreach ($this->parseIntegers('BYHOUR', $value, '', $lineNumber) as [$item, $number]) {
            $numbers[] = $number >= 0 && $number <= 23 ? $number : throw self::outOfRange('BYHOUR', $item, 'allowed values are 0 to 23', $lineNumber);
        }

        return $numbers;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<int<-31, -1>|int<1, 31>>
     *
     * @throws ParseException
     */
    private function parseByMonthDay(
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        $numbers = [];

        foreach ($this->parseIntegers('BYMONTHDAY', $value, '+-', $lineNumber) as [$item, $number]) {
            $numbers[] = $number !== 0 && $number >= -31 && $number <= 31 ? $number : throw self::outOfRange('BYMONTHDAY', $item, 'allowed values are -31 to -1 and 1 to 31', $lineNumber);
        }

        return $numbers;
    }

    /**
     * BYYEARDAY and BYSETPOS have the same range.
     *
     * @param positive-int|null $lineNumber
     * @return list<int<-366, -1>|int<1, 366>>
     *
     * @throws ParseException
     */
    private function parseYearDays(
        string $name,
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        $numbers = [];

        foreach ($this->parseIntegers($name, $value, '+-', $lineNumber) as [$item, $number]) {
            $numbers[] = $number !== 0 && $number >= -366 && $number <= 366 ? $number : throw self::outOfRange($name, $item, 'allowed values are -366 to -1 and 1 to 366', $lineNumber);
        }

        return $numbers;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<int<-53, -1>|int<1, 53>>
     *
     * @throws ParseException
     */
    private function parseByWeekNo(
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        $numbers = [];

        foreach ($this->parseIntegers('BYWEEKNO', $value, '+-', $lineNumber) as [$item, $number]) {
            $numbers[] = $number !== 0 && $number >= -53 && $number <= 53 ? $number : throw self::outOfRange('BYWEEKNO', $item, 'allowed values are -53 to -1 and 1 to 53', $lineNumber);
        }

        return $numbers;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<int<1, 12>>
     *
     * @throws ParseException
     */
    private function parseByMonth(
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        if ($value !== null && preg_match('~(?:^|,)\d+L(?:,|\z)~i', $value) === 1) {
            throw ParseErrors::create(sprintf('Invalid BYMONTH value "%s": leap months (RFC 7529) do not exist in the GREGORIAN calendar', $value), $lineNumber);
        }

        $numbers = [];

        foreach ($this->parseIntegers('BYMONTH', $value, '', $lineNumber) as [$item, $number]) {
            $numbers[] = $number >= 1 && $number <= 12 ? $number : throw self::outOfRange('BYMONTH', $item, 'allowed values are 1 to 12', $lineNumber);
        }

        return $numbers;
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<array{string, int}> the text and the number of each item
     *
     * @throws ParseException
     */
    private function parseIntegers(
        string $name,
        ?string $value,
        string $allowedSigns,
        ?int $lineNumber,
    ): array
    {
        $numbers = [];

        foreach ($this->splitList($name, $value, $lineNumber) as $item) {
            $numbers[] = [$item, $this->parseInteger($name, $item, $item, $allowedSigns, $lineNumber)];
        }

        return $numbers;
    }

    /**
     * @param positive-int|null $lineNumber
     */
    private static function outOfRange(
        string $name,
        string $item,
        string $allowed,
        ?int $lineNumber,
    ): ParseException
    {
        return ParseErrors::create(sprintf('Invalid %s value "%s": %s', $name, $item, $allowed), $lineNumber);
    }

    /**
     * @param positive-int|null $lineNumber
     * @return list<string>
     *
     * @throws ParseException
     */
    private function splitList(
        string $name,
        ?string $value,
        ?int $lineNumber,
    ): array
    {
        if ($value === null) {
            return [];
        }

        $items = explode(',', $value);

        foreach ($items as $item) {
            if ($item === '') {
                throw ParseErrors::create(sprintf('Invalid %s value "%s": the list contains an empty item', $name, $value), $lineNumber);
            }
        }

        return $items;
    }

    /**
     * @param string            $item         the list item, for messages
     * @param string            $number       digits with an optional sign
     * @param string            $allowedSigns the signs that the grammar allows for this part
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    private function parseInteger(
        string $name,
        string $item,
        string $number,
        string $allowedSigns,
        ?int $lineNumber,
    ): int
    {
        if (preg_match('~^([+-]?)(\d+)\z~', $number, $matches) !== 1) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": expected an integer', $name, $item), $lineNumber);
        }

        if (!str_contains($allowedSigns, $matches[1])) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": a sign is not allowed', $name, $item), $lineNumber);
        }

        $digits = ltrim($matches[2], '0');
        $maxDigits = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($maxDigits) || (strlen($digits) === strlen($maxDigits) && strcmp($digits, $maxDigits) > 0)) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": the number is larger than %s', $name, $item, $maxDigits), $lineNumber);
        }

        return (int) $number;
    }

    /**
     * @param list<T> $values
     * @return list<T>
     *
     * @template T
     */
    private function ignorePart(
        array $values,
        ParseWarningCode $code,
        string $name,
        string $reason,
        RawRule $rule,
    ): array
    {
        if ($values === []) {
            return [];
        }

        $this->context->warn($code, sprintf('Ignored %s=%s: %s', $name, $rule->parts[$name] ?? '', $reason), $rule->lineNumber);

        return [];
    }

    /**
     * @param list<WeekdayNum> $byDay
     * @param list<int>        $byWeekNo
     * @return list<WeekdayNum>
     */
    private function ignoreOrdinals(
        array $byDay,
        Frequency $frequency,
        array $byWeekNo,
        RawRule $rule,
    ): array
    {
        $reason = match (true) {
            $frequency !== Frequency::Monthly && $frequency !== Frequency::Yearly => 'ordinals are allowed only with FREQ=MONTHLY or FREQ=YEARLY',
            $byWeekNo !== [] => 'ordinals are not allowed together with BYWEEKNO',
            default => null,
        };

        if ($reason === null) {
            return $byDay;
        }

        $weekdays = [];
        $hasOrdinal = false;

        foreach ($byDay as $weekdayNum) {
            $hasOrdinal = $hasOrdinal || $weekdayNum->ordinal !== null;
            $weekdays[$weekdayNum->weekday->value] = WeekdayNum::every($weekdayNum->weekday);
        }

        if (!$hasOrdinal) {
            return $byDay;
        }

        $this->context->warn(
            ParseWarningCode::ForbiddenRulePartIgnored,
            sprintf('Ignored the ordinals in BYDAY=%s: %s', $rule->parts['BYDAY'] ?? '', $reason),
            $rule->lineNumber,
        );

        return array_values($weekdays);
    }

    /**
     * Lenient mode: gives UNTIL the value type of the start (RFC 5545 section 3.3.10 requires the same type).
     * Returns null when the types already match.
     */
    private function convertUntil(
        CalendarDate|FloatingDateTime|DateTimeImmutable $until,
        CalendarDate|FloatingDateTime|DateTimeImmutable $start,
    ): CalendarDate|FloatingDateTime|DateTimeImmutable|null
    {
        if ($start instanceof CalendarDate) {
            return $until instanceof CalendarDate ? null : self::toFloating($until)->getDate();
        }

        if ($until instanceof DateTimeImmutable) {
            return $start instanceof FloatingDateTime ? self::toFloating($until) : null;
        }

        if ($start instanceof FloatingDateTime) {
            return $until instanceof CalendarDate ? self::getEndOfDay($until) : null;
        }

        return ($until instanceof CalendarDate ? self::getEndOfDay($until) : $until)->atTimeZone($start->getTimezone());
    }

    /**
     * The wall-clock time of a floating value, or of a UTC value in UTC.
     */
    private static function toFloating(FloatingDateTime|DateTimeImmutable $value): FloatingDateTime
    {
        return $value instanceof FloatingDateTime ? $value : FloatingDateTime::fromLocalSeconds($value->getTimestamp());
    }

    private static function getEndOfDay(CalendarDate $date): FloatingDateTime
    {
        return FloatingDateTime::fromLocalSeconds(($date->toDayNumber() + 1) * Civil::SECONDS_PER_DAY - 1);
    }

}
