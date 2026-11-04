<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

use DateTimeImmutable;
use ShipMonk\RRule\Exception\InvalidRecurrenceException;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Internal\Civil;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use ShipMonk\RRule\Parser\ParseWarningCode;
use ShipMonk\RRule\Recurrence;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;

/**
 * Parses DTSTART, RRULE, RDATE, EXDATE and EXRULE content lines (RFC 5545 sections 3.8.2.4 and 3.8.5).
 *
 * The first pass reads all lines and finds the start. The second pass parses the rules and dates,
 * because their value types depend on the start.
 *
 * @internal
 */
final class RecurrenceTextParser
{

    private const VALUE_DATE = 'DATE';
    private const VALUE_DATE_TIME = 'DATE-TIME';
    private const VALUE_PERIOD = 'PERIOD';

    private readonly RuleParser $ruleParser;

    public function __construct(
        private readonly ParseContext $context,
    )
    {
        $this->ruleParser = new RuleParser($context);
    }

    /**
     * @return Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable>
     *
     * @throws ParseException
     */
    public function parse(string $text): Recurrence
    {
        $startLine = null;
        $entries = [];

        foreach (ContentLineReader::read($text) as $line) {
            if ($line->name === 'DTSTART') {
                if ($startLine !== null) {
                    throw ParseErrors::create(sprintf('DTSTART occurs more than once (first on line %d)', $startLine->lineNumber), $line->lineNumber);
                }

                $startLine = $line;
            } elseif ($line->name === 'RRULE' || $line->name === 'EXRULE') {
                if ($line->name === 'EXRULE') {
                    $this->context->deviate(
                        ParseWarningCode::ExclusionRuleAccepted,
                        'EXRULE is not allowed, because RFC 5545 removed it',
                        'Accepted EXRULE, which RFC 5545 removed',
                        $line->lineNumber,
                    );
                }

                $entries[] = [$line, $this->ruleParser->split($line->value, $line->lineNumber)];
            } elseif ($line->name === 'RDATE' || $line->name === 'EXDATE') {
                $entries[] = [$line, null];
            } else {
                $this->context->deviate(
                    ParseWarningCode::UnknownPropertyIgnored,
                    sprintf('Unknown property %s (allowed: DTSTART, RRULE, RDATE, EXDATE)', $line->name),
                    sprintf('Ignored the property %s', $line->name),
                    $line->lineNumber,
                );
            }
        }

        $start = $this->resolveStart($startLine, $entries);
        $rules = [];
        $exclusionRules = [];
        $lineNumber = null;

        try {
            $recurrence = $this->createRecurrence($startLine, $start);

            foreach ($entries as [$line, $rawRule]) {
                $lineNumber = $line->lineNumber;

                if ($rawRule === null) {
                    $recurrence = $this->addDates($recurrence, $line, $start);
                } elseif ($line->name === 'RRULE') {
                    $rules[] = $this->ruleParser->build($rawRule, $start);
                    $recurrence = $recurrence->withRules(...$rules);
                } else {
                    $exclusionRules[] = $this->ruleParser->build($rawRule, $start);
                    $recurrence = $recurrence->withExclusionRules(...$exclusionRules);
                }
            }
        } catch (InvalidRecurrenceException $e) {
            throw ParseErrors::create($e->getMessage(), $lineNumber, $e);
        }

        return $recurrence;
    }

    /**
     * A zoned start keeps the local time as written (RFC 5545 section 3.3.5): a start in a DST gap moves forward,
     * but the rules keep the written time.
     *
     * @return Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable>
     *
     * @throws InvalidRecurrenceException
     * @throws ParseException
     */
    private function createRecurrence(
        ?ContentLine $startLine,
        LocalDate|LocalDateTime|DateTimeImmutable $start,
    ): Recurrence
    {
        if ($startLine !== null && $start instanceof DateTimeImmutable && $startLine->getParameter('TZID') !== null) {
            $written = ValueParser::parseDateTime($startLine->value, $startLine->name, $startLine->lineNumber);

            if ($written instanceof LocalDateTime) {
                return Recurrence::inTimeZone($written, $start->getTimezone());
            }
        }

        return Recurrence::of($start);
    }

    /**
     * @param list<array{ContentLine, RawRule|null}> $entries
     *
     * @throws ParseException
     */
    private function resolveStart(
        ?ContentLine $startLine,
        array $entries,
    ): LocalDate|LocalDateTime|DateTimeImmutable
    {
        $start = $startLine === null ? null : [$this->parseValues($startLine, [self::VALUE_DATE_TIME, self::VALUE_DATE], false)[0][1], $startLine->lineNumber];

        foreach ($entries as [$line, $rawRule]) {
            $value = $rawRule?->start;

            if ($value === null) {
                continue;
            }

            if ($start === null) {
                $start = [ValueParser::parseDateOrDateTime($value, 'DTSTART', $line->lineNumber), $line->lineNumber];
                $this->context->warn(
                    ParseWarningCode::StartInsideRule,
                    sprintf('Used DTSTART=%s from inside %s as the start', $value, $line->name),
                    $line->lineNumber,
                );
            } else {
                $this->context->warn(
                    ParseWarningCode::StartInsideRule,
                    sprintf('Ignored DTSTART=%s inside %s, because the start is set on line %d', $value, $line->name, $start[1]),
                    $line->lineNumber,
                );
            }
        }

        return ($start ?? throw ParseErrors::create('DTSTART is required', null))[0];
    }

    /**
     * @param Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable> $recurrence
     * @return Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable>
     *
     * @throws InvalidRecurrenceException
     * @throws ParseException
     */
    private function addDates(
        Recurrence $recurrence,
        ContentLine $line,
        LocalDate|LocalDateTime|DateTimeImmutable $start,
    ): Recurrence
    {
        $isAddition = $line->name === 'RDATE';
        $valueTypes = $isAddition ? [self::VALUE_DATE_TIME, self::VALUE_DATE, self::VALUE_PERIOD] : [self::VALUE_DATE_TIME, self::VALUE_DATE];
        $dates = [];
        $days = [];
        $convertedText = null;

        foreach ($this->parseValues($line, $valueTypes, true) as [$text, $value]) {
            if ($start instanceof LocalDate) {
                if (!$value instanceof LocalDate) {
                    throw ParseErrors::create(sprintf('%s value "%s" is a DATE-TIME, but DTSTART is a DATE', $line->name, $text), $line->lineNumber);
                }

                $dates[] = $value;
            } elseif ($value instanceof LocalDate) {
                if ($isAddition) {
                    $convertedText ??= $text;
                    $dates[] = $start instanceof LocalDateTime
                        ? LocalDateTime::fromLocalSeconds($value->toDayNumber() * Civil::SECONDS_PER_DAY)
                        : $value->atStartOfDayIn($start->getTimezone());
                } else {
                    $days[] = $value;
                }
            } elseif ($start instanceof LocalDateTime && $value instanceof DateTimeImmutable) {
                throw ParseErrors::create(sprintf('%s value "%s" has a time zone, but DTSTART is floating', $line->name, $text), $line->lineNumber);
            } elseif ($start instanceof DateTimeImmutable && $value instanceof LocalDateTime) {
                throw ParseErrors::create(sprintf('%s value "%s" is floating, but DTSTART has a time zone', $line->name, $text), $line->lineNumber);
            } else {
                $dates[] = $value;
            }
        }

        if ($convertedText !== null) {
            $this->context->deviate(
                ParseWarningCode::DateAdditionForDateTimeStart,
                sprintf('RDATE value "%s" is a DATE, but DTSTART is a DATE-TIME', $convertedText),
                'Converted the RDATE;VALUE=DATE values to the start of each day, because DTSTART is a DATE-TIME',
                $line->lineNumber,
            );
        }

        if ($days !== []) {
            $this->context->warn(
                ParseWarningCode::DateExclusionForDateTimeStart,
                'EXDATE;VALUE=DATE with a DATE-TIME start excludes every occurrence on these dates',
                $line->lineNumber,
            );
        }

        $recurrence = $isAddition
            ? $recurrence->withAddedDates(...$recurrence->getAddedDates(), ...$dates)
            : $recurrence->withExcludedDates(...$recurrence->getExcludedDates(), ...$dates);

        return $days === [] ? $recurrence : $recurrence->withExcludedDays(...$recurrence->getExcludedDays(), ...$days);
    }

    /**
     * @param non-empty-list<string> $valueTypes the allowed VALUE parameters; the first one is the default
     * @return non-empty-list<array{string, LocalDate|LocalDateTime|DateTimeImmutable}> the text and the value
     *
     * @throws ParseException
     */
    private function parseValues(
        ContentLine $line,
        array $valueTypes,
        bool $isList,
    ): array
    {
        $valueParameter = $line->getParameter('VALUE');
        $valueType = $valueParameter === null ? $valueTypes[0] : ValueParser::upper($valueParameter);

        if (!in_array($valueType, $valueTypes, true)) {
            throw ParseErrors::create(
                sprintf('VALUE=%s is not allowed for %s (allowed: %s)', $valueParameter, $line->name, implode(', ', $valueTypes)),
                $line->lineNumber,
            );
        }

        $timeZoneId = $line->getParameter('TZID');

        if ($valueType === self::VALUE_DATE && $timeZoneId !== null) {
            throw ParseErrors::create(sprintf('TZID must not be used with VALUE=DATE (RFC 5545 section 3.2.19) in %s', $line->name), $line->lineNumber);
        }

        $timeZoneIgnored = false;
        $values = [];

        foreach ($isList ? explode(',', $line->value) : [$line->value] as $text) {
            if ($valueType === self::VALUE_DATE) {
                if (ValueParser::isDateTime($text)) {
                    throw ParseErrors::create(sprintf('Invalid %s value "%s": VALUE=DATE does not allow a time', $line->name, $text), $line->lineNumber);
                }

                $values[] = [$text, ValueParser::parseDate($text, $line->name, $line->lineNumber)];
                continue;
            }

            if ($valueType === self::VALUE_DATE_TIME && ValueParser::isDate($text)) {
                throw ParseErrors::create(sprintf('Invalid %s value "%s": a DATE value requires VALUE=DATE', $line->name, $text), $line->lineNumber);
            }

            $dateTime = ValueParser::parseDateTime($valueType === self::VALUE_PERIOD ? $this->getPeriodStart($line, $text) : $text, $line->name, $line->lineNumber);

            if ($timeZoneId !== null && $dateTime instanceof LocalDateTime) {
                $values[] = [$text, $dateTime->atTimeZone(ValueParser::parseTimeZone($timeZoneId, $line->lineNumber))];
            } else {
                $timeZoneIgnored = $timeZoneIgnored || $timeZoneId !== null;
                $values[] = [$text, $dateTime];
            }
        }

        if ($timeZoneIgnored) {
            $this->context->deviate(
                ParseWarningCode::TimeZoneIgnoredForUtcValue,
                sprintf('TZID must not be used with a UTC value (ending with Z) in %s', $line->name),
                sprintf('Ignored TZID=%s, because the %s value is in UTC', $timeZoneId, $line->name),
                $line->lineNumber,
            );
        }

        if ($valueType === self::VALUE_PERIOD) {
            $this->context->warn(ParseWarningCode::PeriodEndIgnored, 'Ignored the end of each RDATE period: only the period start is used', $line->lineNumber);
        }

        return $values;
    }

    /**
     * Checks a PERIOD value (RFC 5545 section 3.3.9) and returns its start.
     *
     * @throws ParseException
     */
    private function getPeriodStart(
        ContentLine $line,
        string $text,
    ): string
    {
        if (preg_match('~^([^/]*)/([^/]*)\z~', $text, $matches) !== 1) {
            throw ParseErrors::create(sprintf('Invalid %s value "%s": expected a PERIOD (start/end or start/duration)', $line->name, $text), $line->lineNumber);
        }

        if (!ValueParser::isDuration($matches[2])) {
            ValueParser::parseDateTime($matches[2], 'PERIOD end', $line->lineNumber);
        }

        return $matches[1];
    }

}
