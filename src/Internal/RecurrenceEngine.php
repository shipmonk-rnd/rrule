<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use ShipMonk\RRule\Count;
use ShipMonk\RRule\DtStartPolicy;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Internal\Values\InstantValues;
use ShipMonk\RRule\Internal\Values\ValueConverter;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRule\Until;
use function array_flip;
use function array_map;
use function intdiv;
use function max;
use function min;
use function sort;
use const PHP_INT_MAX;

/**
 * Evaluates a recurrence set (RFC 5545 section 3.8.5.3): the start, the rules and the added dates, minus the
 * excluded dates, the excluded days and the exclusion rules. Every call works on fresh state.
 *
 * @template T of LocalDate|LocalDateTime|DateTimeImmutable
 *
 * @internal
 */
final class RecurrenceEngine
{

    /**
     * After the local time 9999-12-31T23:59:59 plus the largest UTC offset, nothing can occur.
     */
    private const MAX_POSITION = 253_402_300_799 + 2 * Civil::SECONDS_PER_DAY;

    private const EXCLUSION_STEPS_BEFORE_SKIP = 64;

    /**
     * A scan of this many occurrences costs about as much as the searches of a bisection.
     */
    private const SCANNED_BEFORE_BISECTION = 64;

    private readonly ?DateTimeZone $timeZone;

    private readonly int $startPosition;

    /**
     * @param ValueConverter<T>    $values
     * @param T                    $start
     * @param int                  $startLocal     the start as local wall-clock seconds (for a zoned start, as written)
     * @param list<RecurrenceRule> $rules
     * @param list<T>              $addedDates
     * @param list<T>              $excludedDates
     * @param list<LocalDate>      $excludedDays
     * @param list<RecurrenceRule> $exclusionRules
     * @param positive-int         $searchLimit
     */
    public function __construct(
        private readonly ValueConverter $values,
        LocalDate|LocalDateTime|DateTimeImmutable $start,
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
        $this->timeZone = $values instanceof InstantValues ? $values->getTimeZone() : null;
        $this->startPosition = $values->toPosition($start);
    }

    /**
     * Unix seconds for instants, local seconds for local date-times and dates.
     */
    private static function positionOf(LocalDate|LocalDateTime|DateTimeImmutable $value): int
    {
        if ($value instanceof LocalDate) {
            return $value->toDayNumber() * Civil::SECONDS_PER_DAY;
        }

        if ($value instanceof LocalDateTime) {
            return $value->toLocalSeconds();
        }

        return $value->getTimestamp();
    }

    /**
     * Positions are whole seconds, so an exclusive bound x is the inclusive bound x + 1.
     *
     * @param int|null $from start of the range (inclusive); null for the beginning
     * @param int|null $to   end of the range (exclusive); null for no end
     * @return Generator<int, T>
     */
    public function iterate(
        ?int $from,
        ?int $to,
    ): Generator
    {
        foreach ($this->iteratePositions($from, $to) as $position) {
            yield $this->toValue($position);
        }
    }

    /**
     * Scans a window before the end and keeps the last occurrence. A window with many occurrences (a dense rule)
     * is not scanned to its end: the search then halves the range after the last scanned occurrence until no
     * later occurrence is left. Every such step is a search for one occurrence.
     *
     * @param int $end exclusive
     * @return T|null
     */
    public function findLastBefore(int $end): LocalDate|LocalDateTime|DateTimeImmutable|null
    {
        // no occurrence is after the supported range, so the search windows need not start beyond it
        $end = min($end, self::MAX_POSITION);
        $window = $this->getInitialWindow();

        while (true) {
            $from = $window === null ? null : $end - $window;

            if ($from !== null && $from <= $this->startPosition) {
                $from = null;
            }

            $last = null;
            /** @infection-ignore-all */
            $scanned = 0;

            foreach ($this->iteratePositions($from, $end) as $candidate) {
                $last = $candidate;

                // A rule with COUNT has no window: every search expands it from the start, so one scan is cheaper.
                // When the bisection starts only changes how much work a query does, never its result.
                /** @infection-ignore-all */
                if ($window !== null && ++$scanned === self::SCANNED_BEFORE_BISECTION) {
                    return $this->toValue($this->bisectLastBefore($last, $end));
                }
            }

            if ($last !== null || $from === null) {
                return $last === null ? null : $this->toValue($last);
            }

            $window *= 8;
        }
    }

    /**
     * @param int $last an occurrence
     * @param int $end  exclusive
     */
    private function bisectLastBefore(
        int $last,
        int $end,
    ): int
    {
        $before = $end; // no occurrence is in [$before, $end)

        while ($before - $last > 1) {
            $middle = $last + intdiv($before - $last, 2);
            $later = $this->findFirst($middle, $before);

            if ($later === null) {
                $before = $middle;
            } else {
                $last = $later;
            }
        }

        return $last;
    }

    private function findFirst(
        ?int $from,
        int $to,
    ): ?int
    {
        foreach ($this->iteratePositions($from, $to) as $position) {
            return $position;
        }

        return null;
    }

    /**
     * @return Generator<int, int>
     */
    private function iteratePositions(
        ?int $from,
        ?int $to,
    ): Generator
    {
        $budget = new SearchBudget($this->searchLimit);
        $resolver = $this->timeZone === null ? null : new TimeZoneResolver($this->timeZone);
        $startLocal = $this->startLocal;

        $streams = [];
        $startCounts = $this->dtStartPolicy === DtStartPolicy::Always;

        foreach ($this->rules as $rule) {
            $streams[] = $this->createRuleStream($rule, $startLocal, $resolver, $budget, $startCounts)->generate($from, $to);
        }

        if ($this->rules === [] || $startCounts) {
            $streams[] = self::listStream([$this->startPosition]);
        }

        $streams[] = self::listStream($this->toSortedPositions($this->addedDates));

        $exclusionStreams = [];

        foreach ($this->exclusionRules as $rule) {
            $ruleStream = $this->createRuleStream($rule, $startLocal, $resolver, $budget, false);
            $exclusionStreams[] = [$ruleStream, $ruleStream->generate($from, $to)];
        }

        $excludedPositions = array_flip($this->toSortedPositions($this->excludedDates));
        $excludedDays = array_flip(array_map(static fn (LocalDate $day): int => $day->toDayNumber(), $this->excludedDays));

        while (true) {
            $next = null;

            foreach ($streams as $stream) {
                if ($stream->valid() && ($next === null || $stream->current() < $next)) {
                    $next = $stream->current();
                }
            }

            if ($next === null || ($to !== null && $next >= $to)) {
                return;
            }

            $included = ($from === null || $next >= $from)
                && !isset($excludedPositions[$next])
                && !($excludedDays !== [] && $this->isExcludedDay($next, $resolver, $excludedDays))
                && !self::isProducedByAny($exclusionStreams, $next, $to);

            if ($included) {
                $budget->reset(); // the limit bounds the search for one occurrence, not the length of an iteration
                yield $next;
            }

            // Streams advance only after the yield, so a query that stops at this occurrence computes no more.
            foreach ($streams as $stream) {
                while ($stream->valid() && $stream->current() <= $next) {
                    $stream->next();
                }
            }
        }
    }

    /**
     * @param array<int, int> $excludedDays day number => index
     */
    private function isExcludedDay(
        int $position,
        ?TimeZoneResolver $resolver,
        array $excludedDays,
    ): bool
    {
        $local = $resolver === null ? $position : $resolver->unixToLocal($position);

        return isset($excludedDays[Civil::floorDiv($local, Civil::SECONDS_PER_DAY)]);
    }

    /**
     * An exclusion stream far behind the position starts again at the position, so a dense exclusion rule does
     * not step through every candidate between two occurrences.
     *
     * @param list<array{RuleStream, Generator<int, int>}> $streams
     */
    private static function isProducedByAny(
        array &$streams,
        int $position,
        ?int $to,
    ): bool
    {
        foreach ($streams as $index => [$ruleStream, $stream]) {
            for ($steps = 0; $stream->valid() && $stream->current() < $position; $steps++) {
                if ($steps === self::EXCLUSION_STEPS_BEFORE_SKIP && $ruleStream->canSkipAhead()) {
                    $stream = $ruleStream->generate($position, $to);
                    $streams[$index] = [$ruleStream, $stream];
                    continue;
                }

                $stream->next();
            }

            if ($stream->valid() && $stream->current() === $position) {
                return true;
            }
        }

        return false;
    }

    private function createRuleStream(
        RecurrenceRule $rule,
        int $startLocal,
        ?TimeZoneResolver $resolver,
        SearchBudget $budget,
        bool $startCounts,
    ): RuleStream
    {
        $plan = ExpansionPlan::compile($rule, $startLocal);

        return new RuleStream(
            new LocalRuleIterator($plan, new DateMatcher($plan), $budget),
            $resolver,
            $this->startPosition,
            $startCounts,
            $rule->end instanceof Count ? $rule->end->value : null,
            $rule->end instanceof Until ? self::positionOf($rule->end->value) : null,
        );
    }

    /**
     * @param list<int> $positions sorted
     * @return Generator<int, int>
     */
    private static function listStream(array $positions): Generator
    {
        yield from $positions;
    }

    /**
     * @param list<T> $values
     * @return list<int>
     */
    private function toSortedPositions(array $values): array
    {
        $positions = array_map($this->values->toPosition(...), $values);
        sort($positions);

        return $positions;
    }

    /**
     * How far back the first try of {@see self::findLastBefore()} looks: two periods of the longest rule.
     * Null when a rule has COUNT, because such a rule must be expanded from the start.
     * The size only changes how much work a query does, never its result.
     *
     * @infection-ignore-all
     */
    private function getInitialWindow(): ?int
    {
        $window = Civil::SECONDS_PER_DAY;

        foreach ([...$this->rules, ...$this->exclusionRules] as $rule) {
            if ($rule->end instanceof Count) {
                return null;
            }

            $periodSeconds = match ($rule->frequency) {
                Frequency::Yearly => 366 * Civil::SECONDS_PER_DAY,
                Frequency::Monthly => 31 * Civil::SECONDS_PER_DAY,
                Frequency::Weekly => 7 * Civil::SECONDS_PER_DAY,
                Frequency::Daily => Civil::SECONDS_PER_DAY,
                Frequency::Hourly => 3600,
                Frequency::Minutely => 60,
                Frequency::Secondly => 1,
            };

            if ($rule->interval > intdiv(PHP_INT_MAX, 2 * $periodSeconds)) {
                return null;
            }

            $window = max($window, 2 * $periodSeconds * $rule->interval);
        }

        return $window;
    }

    /**
     * @return T
     */
    private function toValue(int $position): LocalDate|LocalDateTime|DateTimeImmutable
    {
        return $this->values->fromPosition($position);
    }

}
