<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use DateTimeImmutable;
use DateTimeZone;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\Exception\SerializationException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_map;
use function implode;
use function ord;
use function sprintf;
use function strlen;
use function strpbrk;
use function substr;

/**
 * Writes rules and recurrences as RFC 5545 text. The parser reads the output back to equal objects.
 *
 * @internal
 */
final class RuleSerializer
{

    private const MAX_LINE_OCTETS = 75;

    /**
     * A UTF-8 character has at most 3 continuation bytes.
     */
    private const MAX_CONTINUATION_OCTETS = 3;

    /**
     * The RECUR value in canonical part order. Parts with their default value are left out.
     * FREQ comes first, because RFC 5545 section 3.3.10 requires it from producers (RFC 7529 examples put RSCALE
     * first, which contradicts that requirement).
     */
    public static function serializeRule(RecurrenceRule $rule): string
    {
        $parts = ['FREQ=' . $rule->frequency->value];

        if ($rule->calendarScale !== null) {
            $parts[] = 'RSCALE=' . $rule->calendarScale->value;
        }

        if ($rule->interval !== 1) {
            $parts[] = sprintf('INTERVAL=%d', $rule->interval);
        }

        if ($rule->end instanceof Count) {
            $parts[] = sprintf('COUNT=%d', $rule->end->value);
        } elseif ($rule->end instanceof Until) {
            $parts[] = 'UNTIL=' . self::formatUntil($rule->end);
        }

        $lists = [
            'BYSECOND' => $rule->bySecond,
            'BYMINUTE' => $rule->byMinute,
            'BYHOUR' => $rule->byHour,
            'BYDAY' => array_map(static fn (WeekdayNum $weekdayNum): string => $weekdayNum->toString(), $rule->byDay),
            'BYMONTHDAY' => $rule->byMonthDay,
            'BYYEARDAY' => $rule->byYearDay,
            'BYWEEKNO' => $rule->byWeekNo,
            'BYMONTH' => $rule->byMonth,
            'BYSETPOS' => $rule->bySetPos,
        ];

        foreach ($lists as $name => $values) {
            if ($values !== []) {
                $parts[] = $name . '=' . implode(',', $values);
            }
        }

        if ($rule->weekStart !== Weekday::Monday) {
            $parts[] = 'WKST=' . $rule->weekStart->value;
        }

        if ($rule->skip !== Skip::Omit) {
            $parts[] = 'SKIP=' . $rule->skip->value;
        }

        return implode(';', $parts);
    }

    /**
     * DTSTART, RRULE, RDATE, EXDATE and EXRULE lines, folded at 75 octets, without a trailing line break.
     *
     * For a zoned start, an RDATE or EXDATE instant that its local time cannot express (the second occurrence
     * of a repeated local time) is written in UTC, so that the parser reads back the same instant.
     *
     * @param Recurrence<*> $recurrence
     */
    public static function serializeRecurrence(
        Recurrence $recurrence,
        string $lineBreak,
    ): string
    {
        $start = $recurrence->getStart();
        $timeZone = null;

        if ($start instanceof CalendarDate) {
            $lines = ['DTSTART;VALUE=DATE:' . self::formatDate($start)];
        } elseif ($start instanceof FloatingDateTime) {
            $lines = ['DTSTART:' . self::formatFloating($start)];
        } elseif (ValueKind::isUtc($start->getTimezone())) {
            $lines = ['DTSTART:' . self::formatUtc($start)];
        } else {
            $timeZone = $start->getTimezone();
            $local = $recurrence->getStartLocalTime();

            if ((new TimeZoneResolver($timeZone))->localToUnix($local->toLocalSeconds()) !== $start->getTimestamp()) {
                throw new SerializationException(sprintf(
                    'DTSTART;TZID cannot express the start %s: %s occurs twice in %s, and the text means the first one',
                    $start->format('Y-m-d\\TH:i:sP'),
                    $local->toString(),
                    $timeZone->getName(),
                ));
            }

            $lines = ['DTSTART;TZID=' . self::formatParameterValue($timeZone->getName()) . ':' . self::formatFloating($local)];
        }

        foreach ($recurrence->getRules() as $rule) {
            $lines[] = 'RRULE:' . self::serializeRule($rule);
        }

        $lines = [
            ...$lines,
            ...self::formatDateLines('RDATE', $recurrence->getAddedDates(), $timeZone),
            ...self::formatDateLines('EXDATE', [...$recurrence->getExcludedDates(), ...$recurrence->getExcludedDays()], $timeZone),
        ];

        foreach ($recurrence->getExclusionRules() as $rule) {
            $lines[] = 'EXRULE:' . self::serializeRule($rule);
        }

        return implode($lineBreak, array_map(static fn (string $line): string => self::fold($line, $lineBreak), $lines));
    }

    public static function formatUntil(Until $until): string
    {
        $value = $until->value;

        if ($value instanceof CalendarDate) {
            return self::formatDate($value);
        }

        if ($value instanceof FloatingDateTime) {
            return self::formatFloating($value);
        }

        return self::formatUtc($value);
    }

    /**
     * Splits a content line into lines of at most 75 octets (RFC 5545 section 3.1). A continuation line starts
     * with one space. A multi-byte UTF-8 character is never split.
     */
    public static function fold(
        string $line,
        string $lineBreak,
    ): string
    {
        $folded = '';

        while (strlen($line) > self::MAX_LINE_OCTETS) {
            $cut = self::MAX_LINE_OCTETS;

            while ($cut > self::MAX_LINE_OCTETS - self::MAX_CONTINUATION_OCTETS && self::isContinuationOctet(ord($line[$cut]))) {
                $cut--;
            }

            $folded .= substr($line, 0, $cut) . $lineBreak;
            $line = ' ' . substr($line, $cut);
        }

        return $folded . $line;
    }

    /**
     * Quotes a parameter value that contains ":", ";" or "," (RFC 5545 section 3.1.1).
     */
    public static function formatParameterValue(string $value): string
    {
        return strpbrk($value, ':;,') === false ? $value : '"' . $value . '"';
    }

    /**
     * @param list<CalendarDate|FloatingDateTime|DateTimeImmutable> $values
     * @return list<string>
     */
    private static function formatDateLines(
        string $name,
        array $values,
        ?DateTimeZone $timeZone,
    ): array
    {
        $resolver = $timeZone === null ? null : new TimeZoneResolver($timeZone);
        $zoneParameter = $timeZone === null ? '' : ';TZID=' . self::formatParameterValue($timeZone->getName());
        $groups = [];

        foreach ($values as $value) {
            if ($value instanceof CalendarDate) {
                $groups[';VALUE=DATE'][] = self::formatDate($value);
            } elseif ($value instanceof FloatingDateTime) {
                $groups[''][] = self::formatFloating($value);
            } elseif ($resolver !== null && $resolver->localToUnix(self::getWallSeconds($value)) === $value->getTimestamp()) {
                $groups[$zoneParameter][] = self::formatWallTime($value);
            } else {
                $groups[''][] = self::formatUtc($value);
            }
        }

        $lines = [];

        foreach ($groups as $parameters => $texts) {
            $lines[] = $name . $parameters . ':' . implode(',', $texts);
        }

        return $lines;
    }

    private static function formatDate(CalendarDate $date): string
    {
        return sprintf('%04d%02d%02d', $date->year, $date->month, $date->day);
    }

    private static function formatFloating(FloatingDateTime $dateTime): string
    {
        return sprintf('%04d%02d%02dT%02d%02d%02d', $dateTime->year, $dateTime->month, $dateTime->day, $dateTime->hour, $dateTime->minute, $dateTime->second);
    }

    private static function formatUtc(DateTimeImmutable $instant): string
    {
        return self::formatFloating(FloatingDateTime::fromLocalSeconds($instant->getTimestamp())) . 'Z';
    }

    private static function formatWallTime(DateTimeImmutable $instant): string
    {
        return self::formatFloating(FloatingDateTime::fromLocalSeconds(self::getWallSeconds($instant)));
    }

    private static function getWallSeconds(DateTimeImmutable $instant): int
    {
        return $instant->getTimestamp() + $instant->getOffset();
    }

    private static function isContinuationOctet(int $octet): bool
    {
        return $octet >= 0x80 && $octet < 0xC0;
    }

}
