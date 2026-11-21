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
     * @var list<int> sorted
     */
    private readonly array $addedPositions;

    /**
     * @var array<int, int> position => index
     */
    private readonly array $excludedPositions;

    /**
     * @var array<int, int> day number => index
     */
    private readonly array $excludedDayNumbers;

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
        array $addedDates,
        array $excludedDates,
        array $excludedDays,
        private readonly array $exclusionRules,
        private readonly DtStartPolicy $dtStartPolicy,
        private readonly int $searchLimit,
    )
    {
        $this->timeZone = $values instanceof InstantValues ? $values->getTimeZone() : null;
        $this->startPosition = $values->toPosition($start);
        // a search for the last occurrence iterates many times, so the lists are prepared only once
        $this->addedPositions = $this->toSortedPositions($addedDates);
        $this->excludedPositions = array_flip($this->toSortedPositions($excludedDates));
        $this->excludedDayNumbers = array_flip(array_map(static fn (LocalDate $day): int => $day->toDayNumber(), $excludedDays));
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
     * A rule with COUNT must be expanded from the start. One pass finds the last occurrence of such rules.
     * After it, only the other rules matter, and their search skips ahead.
     *
     * @param int $end exclusive
     * @return T|null
     */
    public function findLastBefore(int $end): LocalDate|LocalDateTime|DateTimeImmutable|null
    {
        // no occurrence is after the supported range, so the search windows need not start beyond it
        $end = min($end, self::MAX_POSITION);
        [$countRules, $otherRules] = $this->splitByCount();

        if ($countRules === []) {
            $last = $this->findLastInWindows(null, $end, $otherRules);
        } else {
            // every pass has the start and the added dates, so this pass is the whole set when no other rule is left
            $last = self::last($this->iterateSubset(null, $end, $countRules, true));

            if ($otherRules !== []) {
                $last = $this->findLastInWindows($last, $end, $otherRules) ?? $last;
            }
        }

        return $last === null ? null : $this->toValue($last);
    }

    /**
     * Scans a window before the end and keeps the last occurrence. A window with many occurrences (a dense rule)
     * is not scanned to its end: the search then halves the range after the last scanned occurrence until no
     * later occurrence is left. Every such step is a search for one occurrence.
     *
     * @param int|null             $lowest the first position to search; null for the beginning
     * @param int                  $end    exclusive
     * @param list<RecurrenceRule> $rules
     */
    private function findLastInWindows(
        ?int $lowest,
        int $end,
        array $rules,
    ): ?int
    {
        /** @infection-ignore-all */
        $window = $rules === [] ? null : self::getInitialWindow([...$rules, ...$this->exclusionRules]);

        if ($window === null) {
            return self::last($this->iterateSubset($lowest, $end, $rules));
        }

        while (true) {
            $from = $end - $window;

            if ($from <= ($lowest ?? $this->startPosition)) {
                $from = $lowest;
            }

            $last = null;
            /** @infection-ignore-all */
            $scanned = 0;

            foreach ($this->iterateSubset($from, $end, $rules) as $candidate) {
                $last = $candidate;

                // When the bisection starts only changes how much work a query does, never its result.
                /** @infection-ignore-all */
                if (++$scanned === self::SCANNED_BEFORE_BISECTION) {
                    return $this->bisectLastBefore($last, $end, $rules);
                }
            }

            if ($last !== null || $from === $lowest) {
                return $last;
            }

            /** @infection-ignore-all */
            $window *= 8;
        }
    }

    /**
     * @param int                  $last  an occurrence
     * @param int                  $end   exclusive
     * @param list<RecurrenceRule> $rules
     */
    private function bisectLastBefore(
        int $last,
        int $end,
        array $rules,
    ): int
    {
        $before = $end; // no occurrence is in [$before, $end)

        while ($before - $last > 1) {
            $middle = $last + intdiv($before - $last, 2);
            $later = self::first($this->iterateSubset($middle, $before, $rules));

            if ($later === null) {
                $before = $middle;
            } else {
                $last = $later;
            }
        }

        return $last;
    }

    /**
     * An exclusion rule with COUNT is expanded from the start in every search, so the set then stays in one scan.
     * Which search a rule gets only changes how much work a query does, never its result.
     *
     * @return array{list<RecurrenceRule>, list<RecurrenceRule>} the rules with COUNT, the other rules
     *
     * @infection-ignore-all
     */
    private function splitByCount(): array
    {
        foreach ($this->exclusionRules as $rule) {
            if ($rule->end instanceof Count) {
                return [[], $this->rules];
            }
        }

        $split = [[], []];

        foreach ($this->rules as $rule) {
            $split[$rule->end instanceof Count ? 0 : 1][] = $rule;
        }

        return $split;
    }

    /**
     * @param Generator<int, int> $positions
     */
    private static function first(Generator $positions): ?int
    {
        return $positions->valid() ? $positions->current() : null;
    }

    /**
     * @param Generator<int, int> $positions
     */
    private static function last(Generator $positions): ?int
    {
        $last = null;

        foreach ($positions as $position) {
            $last = $position;
        }

        return $last;
    }

    /**
     * @return Generator<int, int>
     */
    private function iteratePositions(
        ?int $from,
        ?int $to,
    ): Generator
    {
        return $this->iterateSubset($from, $to, $this->rules);
    }

    /**
     * The occurrences of the set when it has only the given rules.
     *
     * @param list<RecurrenceRule> $rules
     * @param bool                 $finite every rule has COUNT, so an excluded date is progress for the search limit too
     * @return Generator<int, int>
     */
    private function iterateSubset(
        ?int $from,
        ?int $to,
        array $rules,
        bool $finite = false,
    ): Generator
    {
        $budget = new SearchBudget($this->searchLimit);
        $resolver = $this->timeZone === null ? null : new TimeZoneResolver($this->timeZone);
        $startLocal = $this->startLocal;

        $streams = [];
        $startCounts = $this->dtStartPolicy === DtStartPolicy::Always;

        foreach ($rules as $rule) {
            $streams[] = $this->createRuleStream($rule, $startLocal, $resolver, $budget, $startCounts)->generate($from, $to);
        }

        // the whole set decides this, not the given rules: a start that does not match stays out of every part
        if ($this->rules === [] || $startCounts) {
            $streams[] = self::listStream([$this->startPosition]);
        }

        $streams[] = self::listStream($this->addedPositions);

        $exclusionStreams = [];

        foreach ($this->exclusionRules as $rule) {
            $ruleStream = $this->createRuleStream($rule, $startLocal, $resolver, $budget, false);
            $exclusionStreams[] = [$ruleStream, $ruleStream->generate($from, $to)];
        }

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
                && !isset($this->excludedPositions[$next])
                && !($this->excludedDayNumbers !== [] && $this->isExcludedDay($next, $resolver))
                && !self::isProducedByAny($exclusionStreams, $next, $to);

            if ($included || $finite) {
                $budget->reset(); // the limit bounds the search for one occurrence, not the length of an iteration
            }

            if ($included) {
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

    private function isExcludedDay(
        int $position,
        ?TimeZoneResolver $resolver,
    ): bool
    {
        $local = $resolver === null ? $position : $resolver->unixToLocal($position);

        return isset($this->excludedDayNumbers[Civil::floorDiv($local, Civil::SECONDS_PER_DAY)]);
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
     * How far back the first try of {@see self::findLastInWindows()} looks: two periods of the longest rule.
     * Null when a rule has COUNT, because such a rule must be expanded from the start, and when the window
     * does not fit in an integer.
     * The size only changes how much work a query does, never its result.
     *
     * @param list<RecurrenceRule> $rules
     *
     * @infection-ignore-all
     */
    private static function getInitialWindow(array $rules): ?int
    {
        $window = Civil::SECONDS_PER_DAY;

        foreach ($rules as $rule) {
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
