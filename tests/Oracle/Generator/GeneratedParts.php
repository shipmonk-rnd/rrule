<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle\Generator;

use ShipMonk\RRule\Weekday;
use ShipMonk\RRule\WeekdayNum;
use function array_map;
use function implode;
use function in_array;

/**
 * The BYxxx parts of a rule while {@see RandomRuleGenerator} builds it. An empty list means that the part is absent.
 * The typed getters only narrow the types; the RecurrenceRule constructor checks the ranges.
 *
 * @phpstan-type NumericPart 'BYSECOND'|'BYMINUTE'|'BYHOUR'|'BYMONTHDAY'|'BYYEARDAY'|'BYWEEKNO'|'BYMONTH'|'BYSETPOS'
 */
final class GeneratedParts
{

    private const NUMERIC_PARTS = ['BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS'];

    /**
     * Parts whose positive values RFC 5545 allows with a "+" sign.
     */
    private const SIGNED_PARTS = ['BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYSETPOS'];

    /**
     * @var array<NumericPart, list<int>>
     */
    private array $numeric = [];

    /**
     * @var list<array{int|null, Weekday}> ordinal (null for every such weekday) and weekday
     */
    private array $byDay = [];

    /**
     * @param NumericPart $part
     * @param list<int>   $values
     */
    public function set(
        string $part,
        array $values,
    ): void
    {
        $this->numeric[$part] = $values;
    }

    /**
     * @param NumericPart $part
     * @return list<int>
     */
    public function get(string $part): array
    {
        return $this->numeric[$part] ?? [];
    }

    /**
     * @param list<array{int|null, Weekday}> $byDay
     */
    public function setByDay(array $byDay): void
    {
        $this->byDay = $byDay;
    }

    /**
     * @return list<WeekdayNum>
     */
    public function getByDay(): array
    {
        return array_map(
            static fn (array $day): WeekdayNum => $day[0] === null ? WeekdayNum::every($day[1]) : WeekdayNum::of($day[1], $day[0]),
            $this->byDay,
        );
    }

    /**
     * True when a BYxxx part other than BYSETPOS is present.
     */
    public function hasByPart(): bool
    {
        foreach ($this->numeric as $part => $values) {
            if ($part !== 'BYSETPOS' && $values !== []) {
                return true;
            }
        }

        return $this->byDay !== [];
    }

    /**
     * Rarely, positive ordinals and offsets get a "+" sign, which RFC 5545 allows.
     *
     * @return list<string> for example ["BYMONTH=1,2", "BYDAY=-1FR"]
     */
    public function toStrings(SeededRandom $random): array
    {
        $format = static fn (int $value, bool $signed): string => $signed && $value > 0 && $random->chance(3) ? '+' . $value : (string) $value;
        $strings = [];

        foreach (self::NUMERIC_PARTS as $part) {
            $values = $this->get($part);
            $signed = in_array($part, self::SIGNED_PARTS, true);

            if ($values !== []) {
                $strings[] = $part . '=' . implode(',', array_map(static fn (int $value): string => $format($value, $signed), $values));
            }
        }

        if ($this->byDay !== []) {
            $strings[] = 'BYDAY=' . implode(',', array_map(
                static fn (array $day): string => ($day[0] === null ? '' : $format($day[0], true)) . $day[1]->value,
                $this->byDay,
            ));
        }

        return $strings;
    }

    /**
     * @return list<int<0, 60>>
     */
    public function getBySecond(): array
    {
        /** @var list<int<0, 60>> $values */
        $values = $this->get('BYSECOND');

        return $values;
    }

    /**
     * @return list<int<0, 59>>
     */
    public function getByMinute(): array
    {
        /** @var list<int<0, 59>> $values */
        $values = $this->get('BYMINUTE');

        return $values;
    }

    /**
     * @return list<int<0, 23>>
     */
    public function getByHour(): array
    {
        /** @var list<int<0, 23>> $values */
        $values = $this->get('BYHOUR');

        return $values;
    }

    /**
     * @return list<int<-31, -1>|int<1, 31>>
     */
    public function getByMonthDay(): array
    {
        /** @var list<int<-31, -1>|int<1, 31>> $values */
        $values = $this->get('BYMONTHDAY');

        return $values;
    }

    /**
     * @return list<int<-366, -1>|int<1, 366>>
     */
    public function getByYearDay(): array
    {
        /** @var list<int<-366, -1>|int<1, 366>> $values */
        $values = $this->get('BYYEARDAY');

        return $values;
    }

    /**
     * @return list<int<-53, -1>|int<1, 53>>
     */
    public function getByWeekNo(): array
    {
        /** @var list<int<-53, -1>|int<1, 53>> $values */
        $values = $this->get('BYWEEKNO');

        return $values;
    }

    /**
     * @return list<int<1, 12>>
     */
    public function getByMonth(): array
    {
        /** @var list<int<1, 12>> $values */
        $values = $this->get('BYMONTH');

        return $values;
    }

    /**
     * @return list<int<-366, -1>|int<1, 366>>
     */
    public function getBySetPos(): array
    {
        /** @var list<int<-366, -1>|int<1, 366>> $values */
        $values = $this->get('BYSETPOS');

        return $values;
    }

}
