<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential\Generator;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\CalendarScale;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Skip;
use ShipMonk\RRule\Until;
use ShipMonk\RRule\Weekday;
use ShipMonk\RRuleTests\Differential\DtStart;
use ShipMonk\RRuleTests\Differential\DtStartKind;
use function array_map;
use function array_unique;
use function array_values;
use function gmdate;
use function gmmktime;
use function implode;
use function in_array;
use function max;
use function range;
use function sprintf;
use function strtoupper;
use function substr;

/**
 * Generates random recurrence cases (a start and an RRULE) for differential tests. The same seed gives the same
 * cases on every PHP version and platform.
 *
 * Every rule obeys the RFC 5545 MUST / MUST NOT rules, including those that depend on the start:
 * FREQ comes first, COUNT and UNTIL do not occur together, UNTIL has the value type of the start,
 * BYWEEKNO only with YEARLY, no BYYEARDAY with DAILY, WEEKLY or MONTHLY, no BYMONTHDAY with WEEKLY,
 * BYDAY ordinals only with MONTHLY or YEARLY and not with BYWEEKNO, BYSETPOS only with another BYxxx part,
 * and no BYHOUR, BYMINUTE, BYSECOND or sub-daily FREQ with a DATE start. SKIP comes only with RSCALE (RFC 7529).
 *
 * Most rules are common and produce occurrences (small lists, usual values, starts near DST transitions),
 * some contain rare edge cases (ordinals 5 and 53, leap days, BYSECOND=60, UNTIL before the start, "+" signs).
 */
final class RandomRuleGenerator
{

    public const TIME_ZONES = [
        'Europe/Prague',
        'America/New_York',
        'Australia/Lord_Howe',
        'Pacific/Apia',
        'Africa/Casablanca',
        'America/Sao_Paulo',
        'Asia/Kolkata',
        'UTC',
    ];

    /**
     * Local days with a DST transition: [month, nth weekday of the month (negative from the end), ISO weekday,
     * first year, last year, local times]. The rules are approximate. They only make starts near a transition likely.
     */
    private const TRANSITION_DAYS = [
        'Europe/Prague' => [
            [3, -1, 7, 1996, 2037, ['01:30', '02:00', '02:30', '03:00']],
            [10, -1, 7, 1996, 2037, ['01:30', '02:00', '02:30', '03:00']],
        ],
        'America/New_York' => [
            [3, 2, 7, 2007, 2037, ['01:30', '02:00', '02:30', '03:00']],
            [11, 1, 7, 2007, 2037, ['00:30', '01:00', '01:30', '02:00']],
        ],
        'Australia/Lord_Howe' => [
            [10, 1, 7, 2008, 2037, ['01:45', '02:00', '02:15', '02:30']],
            [4, 1, 7, 2008, 2037, ['01:15', '01:30', '01:45', '02:00']],
        ],
        'Pacific/Apia' => [
            [9, -1, 7, 2012, 2020, ['02:30', '03:00', '03:30', '04:00']],
            [4, 1, 7, 2012, 2021, ['02:30', '03:00', '03:30', '04:00']],
        ],
        'Africa/Casablanca' => [
            [3, -1, 7, 2013, 2018, ['01:30', '02:00', '02:30', '03:00']],
            [10, -1, 7, 2013, 2018, ['01:30', '02:00', '02:30', '03:00']],
        ],
        'America/Sao_Paulo' => [
            [11, 1, 7, 2008, 2017, ['23:30', '00:00', '00:30', '01:00']],
            [2, 3, 7, 2008, 2019, ['22:30', '23:00', '23:30']],
        ],
    ];

    /**
     * Single local times at unusual transitions: the day that Samoa skipped, the last Brazilian DST gap at midnight,
     * the Moroccan Ramadan changes.
     */
    private const TRANSITION_TIMES = [
        'Pacific/Apia' => ['2011-12-29 23:30:00', '2011-12-30 09:00:00', '2011-12-31 00:00:00'],
        'America/Sao_Paulo' => ['2018-11-03 23:30:00', '2018-11-04 00:00:00', '2018-11-04 00:30:00'],
        'Africa/Casablanca' => ['2026-02-15 02:30:00', '2026-03-22 02:30:00', '2027-02-07 02:30:00', '2027-03-14 02:30:00'],
    ];

    private const EDGE_DAYS = [
        [1, 1],
        [1, 2],
        [1, 3],
        [1, 4],
        [1, 29],
        [1, 30],
        [1, 31],
        [2, 28],
        [2, 29],
        [3, 31],
        [5, 31],
        [8, 31],
        [10, 31],
        [12, 28],
        [12, 29],
        [12, 30],
        [12, 31],
    ];

    private const SECONDS_PER_DAY = 86_400;

    private readonly SeededRandom $random;

    public function __construct(int $seed)
    {
        $this->random = new SeededRandom($seed);
    }

    public function next(): GeneratedRecurrence
    {
        $kind = $this->generateKind();
        $zone = $kind === DtStartKind::Zoned ? $this->random->pick(self::TIME_ZONES) : null;
        $start = ($zone !== null && $this->random->chance(50) ? $this->generateTransitionTime($zone) : null) ?? $this->generateOrdinaryTime();

        if ($kind === DtStartKind::Date) {
            $start -= $start % self::SECONDS_PER_DAY;
        }

        $frequency = $this->generateFrequency($kind);
        $interval = $this->generateInterval($frequency);
        $parts = new GeneratedParts();
        $this->generateDayParts($parts, $frequency, $start);

        if ($kind !== DtStartKind::Date) {
            $this->generateTimeParts($parts, $frequency, $start, $interval ?? 1);
        }

        if ($parts->get('BYSETPOS') === [] && $parts->hasByPart() && $this->random->chance(12)) {
            // A sub-daily period has only a few instances (one for SECONDLY), so other positions rarely match.
            $parts->set('BYSETPOS', self::isSubDaily($frequency) ? [$this->random->pick([1, -1])] : $this->generateSetPositions());
        }

        $weekStart = $this->random->chance($frequency === Frequency::Weekly || $parts->get('BYWEEKNO') !== [] ? 40 : 15)
            ? $this->random->pick(Weekday::cases())
            : null;
        [$skip, $rscale] = $this->generateSkip($frequency, $parts, $start);
        [$end, $endPart] = $this->generateEnd($kind, $frequency, $start);

        $otherParts = $parts->toStrings($this->random);

        if ($interval !== null) {
            $otherParts[] = 'INTERVAL=' . $interval;
        }

        if ($weekStart !== null) {
            $otherParts[] = 'WKST=' . $weekStart->value;
        }

        if ($rscale) {
            $otherParts[] = 'RSCALE=GREGORIAN';
        }

        if ($skip !== null) {
            $otherParts[] = 'SKIP=' . $skip->value;
        }

        if ($endPart !== null) {
            $otherParts[] = $endPart;
        }

        $rrule = implode(';', ['FREQ=' . $frequency->value, ...$this->random->shuffle($otherParts)]);

        $rule = new RecurrenceRule(
            frequency: $frequency,
            interval: max(1, $interval ?? 1),
            end: $end,
            bySecond: $parts->getBySecond(),
            byMinute: $parts->getByMinute(),
            byHour: $parts->getByHour(),
            byDay: $parts->getByDay(),
            byMonthDay: $parts->getByMonthDay(),
            byYearDay: $parts->getByYearDay(),
            byWeekNo: $parts->getByWeekNo(),
            byMonth: $parts->getByMonth(),
            bySetPos: $parts->getBySetPos(),
            weekStart: $weekStart ?? Weekday::Monday,
            calendarScale: $rscale ? CalendarScale::Gregorian : null,
            skip: $skip ?? Skip::Omit,
        );

        return new GeneratedRecurrence(self::createDtStart($kind, $start, $zone), $rrule, $rule);
    }

    private function generateKind(): DtStartKind
    {
        return match ($this->random->pickWeighted(['date' => 20, 'floating' => 25, 'utc' => 15, 'zoned' => 40])) {
            'date' => DtStartKind::Date,
            'floating' => DtStartKind::Floating,
            'utc' => DtStartKind::Utc,
            'zoned' => DtStartKind::Zoned,
        };
    }

    private function generateFrequency(DtStartKind $kind): Frequency
    {
        $weights = $kind === DtStartKind::Date
            ? ['YEARLY' => 25, 'MONTHLY' => 30, 'WEEKLY' => 25, 'DAILY' => 20]
            : ['YEARLY' => 18, 'MONTHLY' => 22, 'WEEKLY' => 18, 'DAILY' => 20, 'HOURLY' => 10, 'MINUTELY' => 8, 'SECONDLY' => 4];

        return Frequency::from($this->random->pickWeighted($weights));
    }

    /**
     * @return int local time as seconds since 1970-01-01T00:00:00 (as if it were UTC)
     */
    private function generateOrdinaryTime(): int
    {
        $yearChoice = $this->random->int(1, 100);
        $year = match (true) {
            $yearChoice <= 30 => 2026,
            $yearChoice <= 40 => 2024,
            $yearChoice <= 80 => $this->random->int(2020, 2030),
            default => $this->random->int(1990, 2040),
        };

        if ($this->random->chance(30)) {
            [$month, $day] = $this->random->pick(self::EDGE_DAYS);

            if ($month === 2 && $day === 29 && self::getDaysInMonth($year, 2) === 28) {
                $year = 2024;
            }
        } else {
            $month = $this->random->int(1, 12);
            $day = $this->random->int(1, self::getDaysInMonth($year, $month));
        }

        $hourChoice = $this->random->int(1, 100);
        $hour = match (true) {
            $hourChoice <= 30 => 9,
            $hourChoice <= 45 => 0,
            $hourChoice <= 55 => 12,
            default => $this->random->int(0, 23),
        };
        $minuteChoice = $this->random->int(1, 100);
        $minute = match (true) {
            $minuteChoice <= 60 => 0,
            $minuteChoice <= 75 => 30,
            default => $this->random->int(0, 59),
        };
        $second = $this->random->chance(85) ? 0 : $this->random->int(0, 59);

        return self::toSeconds($year, $month, $day, $hour, $minute, $second);
    }

    private function generateTransitionTime(string $zone): ?int
    {
        if (isset(self::TRANSITION_TIMES[$zone]) && $this->random->chance(30)) {
            return self::parseSeconds($this->random->pick(self::TRANSITION_TIMES[$zone]));
        }

        if (!isset(self::TRANSITION_DAYS[$zone])) {
            return null;
        }

        [$month, $nth, $isoWeekday, $firstYear, $lastYear, $times] = $this->random->pick(self::TRANSITION_DAYS[$zone]);
        $year = $this->random->int($firstYear, $lastYear);
        $day = self::getNthWeekday($year, $month, $nth, $isoWeekday);
        $daysBefore = $this->random->pickWeighted([0 => 50, 1 => 15, 2 => 10, 3 => 5, 7 => 15, 14 => 5]);

        return self::parseSeconds(sprintf('%04d-%02d-%02d %s:00', $year, $month, $day, $this->random->pick($times)))
            - $daysBefore * self::SECONDS_PER_DAY;
    }

    private function generateDayParts(
        GeneratedParts $parts,
        Frequency $frequency,
        int $start,
    ): void
    {
        $startMonth = (int) gmdate('n', $start);
        $startDay = (int) gmdate('j', $start);
        $startWeekday = Weekday::from(strtoupper(substr(gmdate('D', $start), 0, 2)));

        switch ($frequency) {
            case Frequency::Yearly:
                $shape = $this->random->pickWeighted(['plain' => 15, 'month' => 40, 'ordinal' => 10, 'weekNo' => 12, 'yearDay' => 10, 'monthDay' => 8, 'mix' => 5]);

                if ($shape === 'month') {
                    $parts->set('BYMONTH', $this->generateMonths($this->random->chance(50) ? $startMonth : null));

                    if ($this->random->chance(50)) {
                        $parts->set('BYMONTHDAY', $this->generateMonthDays(null));
                    } elseif ($this->random->chance(70)) {
                        $parts->setByDay($this->generateOrdinalDays(5));
                    }
                } elseif ($shape === 'ordinal') {
                    $parts->setByDay($this->generateOrdinalDays(53));
                } elseif ($shape === 'weekNo') {
                    $parts->set('BYWEEKNO', $this->generateWeekNumbers());
                    $parts->setByDay($this->random->chance(70) ? $this->generatePlainDays(null) : []);
                } elseif ($shape === 'yearDay') {
                    $parts->set('BYYEARDAY', $this->generateYearDays(null));
                    $parts->setByDay($this->random->chance(25) ? $this->generatePlainDays(null) : []);
                } elseif ($shape === 'monthDay') {
                    $parts->set('BYMONTHDAY', $this->generateMonthDays(null));
                } elseif ($shape === 'mix') {
                    $parts->set('BYMONTH', $this->random->chance(50) ? $this->generateMonths(null) : []);
                    $parts->set('BYWEEKNO', $this->random->chance(50) ? $this->generateWeekNumbers() : []);
                    $parts->set('BYYEARDAY', $this->random->chance(50) ? $this->generateYearDays(null) : []);
                    $parts->set('BYMONTHDAY', $this->random->chance(50) ? $this->generateMonthDays(null) : []);

                    if ($this->random->chance(50)) {
                        $parts->setByDay($parts->get('BYWEEKNO') === [] ? $this->generateOrdinalDays($parts->get('BYMONTH') === [] ? 53 : 5) : $this->generatePlainDays(null));
                    }
                }

                break;

            case Frequency::Monthly:
                $shape = $this->random->pickWeighted(['plain' => 15, 'monthDay' => 30, 'ordinal' => 25, 'plainDay' => 15, 'dayAndMonthDay' => 8, 'month' => 7]);

                if ($shape === 'monthDay') {
                    $parts->set('BYMONTHDAY', $this->generateMonthDays(null));
                } elseif ($shape === 'ordinal') {
                    $parts->setByDay($this->generateOrdinalDays(5));
                } elseif ($shape === 'plainDay') {
                    $parts->setByDay($this->generatePlainDays(null));
                    $parts->set('BYSETPOS', $this->random->chance(50) ? $this->generateSetPositions() : []);
                } elseif ($shape === 'dayAndMonthDay') {
                    $parts->setByDay($this->generatePlainDays(null, 1));
                    $parts->set('BYMONTHDAY', $this->random->chance(50) ? [13] : $this->generateMonthDays(null));
                } elseif ($shape === 'month') {
                    $parts->set('BYMONTH', $this->generateMonths(null));

                    if ($this->random->chance(50)) {
                        $parts->set('BYMONTHDAY', $this->generateMonthDays(null));
                    } else {
                        $parts->setByDay($this->generateOrdinalDays(5));
                    }
                }

                break;

            case Frequency::Weekly:
                $shape = $this->random->pickWeighted(['plain' => 25, 'day' => 60, 'month' => 15]);

                if ($shape === 'month') {
                    $parts->set('BYMONTH', $this->generateMonths(null));
                }

                if ($shape === 'day' || ($shape === 'month' && $this->random->chance(50))) {
                    $parts->setByDay($this->generatePlainDays(null));
                }

                break;

            case Frequency::Daily:
                $shape = $this->random->pickWeighted(['plain' => 45, 'day' => 20, 'month' => 12, 'monthDay' => 13, 'mix' => 10]);
                $parts->setByDay($shape === 'day' || ($shape === 'mix' && $this->random->chance(50)) ? $this->generatePlainDays(null) : []);
                $parts->set('BYMONTH', $shape === 'month' || ($shape === 'mix' && $this->random->chance(50)) ? $this->generateMonths(null) : []);
                $parts->set('BYMONTHDAY', $shape === 'monthDay' || ($shape === 'mix' && $this->random->chance(50)) ? $this->generateMonthDays(null) : []);

                break;

            default:
                // Sub-daily rules only limit by these parts. They contain the start's value, or a rule could search
                // millions of seconds before its first occurrence.
                $parts->set('BYMONTH', $this->random->chance(5) ? $this->generateMonths($startMonth) : []);
                $parts->set('BYMONTHDAY', $this->random->chance(5) ? $this->generateMonthDays($startDay) : []);
                $parts->set('BYYEARDAY', $this->random->chance(3) ? $this->generateYearDays((int) gmdate('z', $start) + 1) : []);
                $parts->setByDay($this->random->chance(10) ? $this->generatePlainDays($startWeekday) : []);
        }
    }

    private function generateTimeParts(
        GeneratedParts $parts,
        Frequency $frequency,
        int $start,
        int $interval,
    ): void
    {
        [$hourChance, $minuteChance, $secondChance] = match ($frequency) {
            Frequency::Yearly, Frequency::Monthly, Frequency::Weekly => [15, 10, 5],
            Frequency::Daily => [25, 15, 5],
            Frequency::Hourly => [30, 30, 10],
            Frequency::Minutely => [20, 30, 20],
            Frequency::Secondly => [15, 20, 30],
        };

        // These parts limit sub-daily rules. Without the start's value, an INTERVAL can step over every match
        // forever (for example FREQ=SECONDLY;INTERVAL=15;BYSECOND=22 from second 0).
        $keepStart = $interval > 1 || $this->random->chance(70);
        $limitsHour = $keepStart && self::isSubDaily($frequency);
        $limitsMinute = $keepStart && ($frequency === Frequency::Minutely || $frequency === Frequency::Secondly);
        $limitsSecond = $keepStart && $frequency === Frequency::Secondly;

        if ($this->random->chance($hourChance)) {
            $parts->set('BYHOUR', $this->generateValues([1 => 55, 2 => 30, 3 => 15], [9, 12, 0, 2, 3, 23, 17, 1], 0, 23, $limitsHour ? (int) gmdate('G', $start) : null));
        }

        if ($this->random->chance($minuteChance)) {
            $parts->set('BYMINUTE', $this->generateValues([1 => 60, 2 => 30, 4 => 10], [0, 30, 15, 45], 0, 59, $limitsMinute ? (int) gmdate('i', $start) : null));
        }

        if ($this->random->chance($secondChance)) {
            $seconds = $this->generateValues([1 => 70, 2 => 30], [0, 30, 59], 0, 59, $limitsSecond ? (int) gmdate('s', $start) : null);
            $parts->set('BYSECOND', $this->random->chance(3) ? [...$seconds, 60] : $seconds);
        }
    }

    /**
     * @return list<int>
     */
    private function generateMonths(?int $required): array
    {
        $months = $this->random->sample(range(1, 12), $this->random->pickWeighted([1 => 60, 2 => 25, 3 => 10, 6 => 5]));

        if ($required !== null && !in_array($required, $months, true)) {
            $months[0] = $required;
        }

        return $months;
    }

    /**
     * @return list<int>
     */
    private function generateMonthDays(?int $required): array
    {
        return $this->generateSignedValues([1 => 55, 2 => 30, 3 => 15], [1, 15, -1, 31, 30, 29, 28, -2, 10, 20, 13], 31, $required);
    }

    /**
     * @return list<int>
     */
    private function generateYearDays(?int $required): array
    {
        return $this->generateSignedValues([1 => 60, 2 => 30, 3 => 10], [1, 100, 200, 365, 366, -1, -365, -366, 60, 59, 32, 256], 366, $required);
    }

    /**
     * @return list<int>
     */
    private function generateWeekNumbers(): array
    {
        return $this->generateSignedValues([1 => 60, 2 => 30, 3 => 10], [1, 2, 10, 20, 26, 52, 53, -1, -2, -53], 53, null);
    }

    /**
     * @return list<int>
     */
    private function generateSetPositions(): array
    {
        $positions = [];
        $count = $this->random->pickWeighted([1 => 75, 2 => 25]);

        for ($i = 0; $i < $count; $i++) {
            $choice = $this->random->int(1, 100);
            $positions[] = match (true) {
                $choice <= 85 => $this->random->pick([1, -1, -1, 2, -2, 3]),
                $choice <= 98 => $this->random->int(1, 31) * ($this->random->chance(50) ? -1 : 1),
                default => $this->random->int(32, 366) * ($this->random->chance(50) ? -1 : 1),
            };
        }

        return array_values(array_unique($positions));
    }

    /**
     * @return list<array{int|null, Weekday}>
     */
    private function generatePlainDays(
        ?Weekday $required,
        ?int $count = null,
    ): array
    {
        $count ??= $this->random->pickWeighted([1 => 40, 2 => 25, 3 => 15, 5 => 15, 7 => 5]);
        $weekdays = $count === 5 && $this->random->chance(50)
            ? [Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday]
            : $this->random->sample(Weekday::cases(), $count);

        if ($required !== null && !in_array($required, $weekdays, true)) {
            $weekdays[0] = $required;
        }

        return array_map(static fn (Weekday $weekday): array => [null, $weekday], $weekdays);
    }

    /**
     * @param 5|53 $maxOrdinal 5 within a month, 53 within a year
     * @return list<array{int|null, Weekday}>
     */
    private function generateOrdinalDays(int $maxOrdinal): array
    {
        $days = [];
        $count = $this->random->pickWeighted([1 => 60, 2 => 30, 3 => 10]);

        for ($i = 0; $i < $count; $i++) {
            $weekday = $this->random->pick(Weekday::cases());

            if ($this->random->chance(15)) {
                $ordinal = null;
            } elseif ($maxOrdinal === 5) {
                $ordinal = $this->random->pickWeighted([1 => 30, 2 => 15, 3 => 10, 4 => 8, 5 => 5, -1 => 25, -2 => 10, -3 => 4, -5 => 3]);
            } else {
                $ordinal = $this->random->chance(75)
                    ? $this->random->pick([1, -1, 1, -1, 2, 10, 20, 26, 52, 53, -53])
                    : $this->random->int(1, 53) * ($this->random->chance(30) ? -1 : 1);
            }

            $days[sprintf('%s%s', $ordinal ?? '', $weekday->value)] = [$ordinal, $weekday];
        }

        return array_values($days);
    }

    /**
     * @param non-empty-array<int, positive-int> $countWeights
     * @param non-empty-list<int>                $common
     * @return list<int>
     */
    private function generateValues(
        array $countWeights,
        array $common,
        int $min,
        int $max,
        ?int $required,
    ): array
    {
        $values = [];
        $count = $this->random->pickWeighted($countWeights);

        for ($i = 0; $i < $count; $i++) {
            $values[] = $this->random->chance(60) ? $this->random->pick($common) : $this->random->int($min, $max);
        }

        if ($required !== null && !in_array($required, $values, true)) {
            $values[0] = $required;
        }

        return array_values(array_unique($values));
    }

    /**
     * Values from -$max to -1 and from 1 to $max.
     *
     * @param non-empty-array<int, positive-int> $countWeights
     * @param non-empty-list<int>                $common
     * @return list<int>
     */
    private function generateSignedValues(
        array $countWeights,
        array $common,
        int $max,
        ?int $required,
    ): array
    {
        $values = [];
        $count = $this->random->pickWeighted($countWeights);

        for ($i = 0; $i < $count; $i++) {
            $values[] = $this->random->chance(60)
                ? $this->random->pick($common)
                : $this->random->int(1, $max) * ($this->random->chance(25) ? -1 : 1);
        }

        if ($required !== null && !in_array($required, $values, true)) {
            $values[0] = $required;
        }

        return array_values(array_unique($values));
    }

    private function generateInterval(Frequency $frequency): ?int
    {
        $choice = $this->random->int(1, 100);

        return match (true) {
            $choice <= 65 => null,
            $choice <= 70 => 1,
            $choice <= 82 => 2,
            $choice <= 88 => 3,
            $choice <= 96 => $this->random->int(4, 12),
            self::isSubDaily($frequency) => $this->random->pick([15, 30, 45, 90, 120]),
            default => $this->random->int(13, 53),
        };
    }

    /**
     * @return array{Skip|null, bool} SKIP (null when absent) and whether RSCALE is present
     */
    private function generateSkip(
        Frequency $frequency,
        GeneratedParts $parts,
        int $start,
    ): array
    {
        $monthEnd = (int) gmdate('j', $start) >= 29;

        foreach ($parts->get('BYMONTHDAY') as $monthDay) {
            $monthEnd = $monthEnd || $monthDay >= 29 || $monthDay <= -29;
        }

        $chance = $monthEnd && ($frequency === Frequency::Monthly || $frequency === Frequency::Yearly) ? 35 : 8;

        if (!$this->random->chance($chance)) {
            return [null, false];
        }

        $skip = $this->random->pickWeighted(['' => 15, 'OMIT' => 20, 'BACKWARD' => 35, 'FORWARD' => 30]);

        return [$skip === '' ? null : Skip::from($skip), true];
    }

    /**
     * @return array{Count|Until|null, string|null}
     */
    private function generateEnd(
        DtStartKind $kind,
        Frequency $frequency,
        int $start,
    ): array
    {
        $choice = $this->random->int(1, 100);

        if ($choice > 70) {
            return [null, null];
        }

        if ($choice <= 45) {
            $countChoice = $this->random->int(1, 100);
            $count = match (true) {
                $countChoice <= 5 => 1,
                $countChoice <= 35 => $this->random->int(2, 5),
                $countChoice <= 75 => $this->random->int(6, 15),
                default => $this->random->int(16, 60),
            };

            return [Count::of($count), 'COUNT=' . $count];
        }

        $unit = match ($frequency) {
            Frequency::Hourly => 3_600,
            Frequency::Minutely => 60,
            Frequency::Secondly => 1,
            Frequency::Daily, Frequency::Weekly, Frequency::Monthly, Frequency::Yearly => self::SECONDS_PER_DAY,
        };
        $stepChoice = $this->random->int(1, 100);
        $steps = match (true) {
            $stepChoice <= 3 => 0,
            $stepChoice <= 5 => -$this->random->int(1, 30),
            $stepChoice <= 35 => $this->random->int(1, 30),
            $stepChoice <= 85 => $this->random->int(31, 400),
            default => $this->random->int(401, 3_000),
        };
        $until = $start + $steps * $unit;

        if ($unit === self::SECONDS_PER_DAY && $kind !== DtStartKind::Date) {
            $timeChoice = $this->random->int(1, 100);
            $midnight = $until - $until % self::SECONDS_PER_DAY;
            $until = match (true) {
                $timeChoice <= 45 => $until,
                $timeChoice <= 75 => $midnight + self::SECONDS_PER_DAY - 1,
                default => $midnight + $this->random->int(0, self::SECONDS_PER_DAY - 1),
            };
        }

        return match ($kind) {
            DtStartKind::Date => [
                Until::date(CalendarDate::of((int) gmdate('Y', $until), (int) gmdate('n', $until), (int) gmdate('j', $until))),
                'UNTIL=' . gmdate('Ymd', $until),
            ],
            DtStartKind::Floating => [
                Until::floating(FloatingDateTime::fromDateTime(new DateTimeImmutable('@' . $until))),
                'UNTIL=' . gmdate('Ymd\THis', $until),
            ],
            // For a zoned start the UNTIL instant is the local time read as UTC: a time near the local time.
            DtStartKind::Utc, DtStartKind::Zoned => [
                Until::instant(new DateTimeImmutable('@' . $until)),
                'UNTIL=' . gmdate('Ymd\THis\Z', $until),
            ],
        };
    }

    private static function isSubDaily(Frequency $frequency): bool
    {
        return $frequency === Frequency::Hourly || $frequency === Frequency::Minutely || $frequency === Frequency::Secondly;
    }

    private static function createDtStart(
        DtStartKind $kind,
        int $start,
        ?string $zone,
    ): DtStart
    {
        return match ($kind) {
            DtStartKind::Date => DtStart::date(gmdate('Ymd', $start)),
            DtStartKind::Floating => DtStart::floating(gmdate('Ymd\THis', $start)),
            DtStartKind::Utc => DtStart::utc(gmdate('Ymd\THis\Z', $start)),
            DtStartKind::Zoned => DtStart::zoned((string) $zone, gmdate('Ymd\THis', $start)),
        };
    }

    private static function getDaysInMonth(
        int $year,
        int $month,
    ): int
    {
        return (int) gmdate('t', self::toSeconds($year, $month, 1, 0, 0, 0));
    }

    /**
     * @param int $nth        1 is the first such weekday of the month, -1 the last
     * @param int $isoWeekday 1 is Monday, 7 is Sunday
     */
    private static function getNthWeekday(
        int $year,
        int $month,
        int $nth,
        int $isoWeekday,
    ): int
    {
        if ($nth > 0) {
            $firstWeekday = (int) gmdate('N', self::toSeconds($year, $month, 1, 0, 0, 0));

            return 1 + ($isoWeekday - $firstWeekday + 7) % 7 + ($nth - 1) * 7;
        }

        $lastDay = self::getDaysInMonth($year, $month);
        $lastWeekday = (int) gmdate('N', self::toSeconds($year, $month, $lastDay, 0, 0, 0));

        return $lastDay - ($lastWeekday - $isoWeekday + 7) % 7 + ($nth + 1) * 7;
    }

    private static function parseSeconds(string $dateTime): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime, new DateTimeZone('UTC'));

        if ($parsed === false) {
            throw new LogicException(sprintf('Invalid date-time %s', $dateTime));
        }

        return $parsed->getTimestamp();
    }

    private static function toSeconds(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
    ): int
    {
        $seconds = gmmktime($hour, $minute, $second, $month, $day, $year);

        if ($seconds === false) {
            throw new LogicException('Invalid date');
        }

        return $seconds;
    }

}
