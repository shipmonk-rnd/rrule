<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use Generator;
use function array_shift;
use function min;
use function sort;

/**
 * The occurrences of one rule as positions: Unix seconds for UTC and zoned starts, local seconds otherwise.
 *
 * The start counts as the first occurrence when the policy says so (RFC 5545 section 3.3.10).
 * Local times resolve to instants per RFC 5545 section 3.3.5. A local time in a DST gap resolves to an instant
 * that a later local time can also produce, and that is earlier than the instants right after the gap. A small
 * buffer restores the order, and equal instants count once (RFC 5545 section 3.8.5.3 ignores duplicate
 * instances), so COUNT counts distinct instants. UNTIL is inclusive.
 *
 * @internal
 */
final class RuleStream
{

    public function __construct(
        private readonly LocalRuleIterator $iterator,
        private readonly ?TimeZoneResolver $resolver,
        private readonly int $startPosition,
        private readonly bool $startCounts,
        private readonly ?int $count,
        private readonly ?int $untilPosition,
    )
    {
    }

    private function canSkipAhead(): bool
    {
        return $this->count === null;
    }

    /**
     * Positions in strictly ascending order. Positions before $from may still appear (the caller filters them);
     * the stream stops at $to.
     *
     * @return Generator<int, int>
     */
    public function generate(
        ?int $from,
        ?int $to,
    ): Generator
    {
        $emitted = 0;
        $last = null;

        if ($this->startCounts) {
            yield $this->startPosition;
            $emitted = 1;
            $last = $this->startPosition;

            if ($this->count === 1) {
                return;
            }
        }

        $limit = $this->untilPosition;

        if ($to !== null) {
            $limit = $limit === null ? $to : min($limit, $to);
        }

        $fromLocal = $from === null || !$this->canSkipAhead() ? null : $this->toLocalLowerBound($from);
        $toLocal = $limit === null ? null : $this->toLocalUpperBound($limit);

        foreach ($this->resolveInOrder($this->iterator->generate($fromLocal, $toLocal)) as $position) {
            // A start in a DST gap resolves forward, so later local times can resolve to earlier instants;
            // the start is the first instance (RFC 5545 section 3.8.5.1).
            if ($position < $this->startPosition || ($last !== null && $position <= $last)) {
                continue;
            }

            if ($limit !== null && $position > $limit) {
                return;
            }

            yield $position;
            $last = $position;

            if ($this->count !== null && ++$emitted >= $this->count) {
                return;
            }
        }
    }

    /**
     * Resolves local times to positions in ascending order (equal positions can repeat).
     *
     * @param Generator<int, int> $locals ascending local seconds
     * @return Generator<int, int>
     */
    private function resolveInOrder(Generator $locals): Generator
    {
        if ($this->resolver === null) {
            yield from $locals;

            return;
        }

        $buffer = [];

        foreach ($locals as $local) {
            $position = $this->resolver->localToUnix($local);
            $buffer[] = $position;
            sort($buffer);
            $ready = $position - $this->resolver->getMaxGapNear($local);

            while ($buffer !== [] && $buffer[0] <= $ready) {
                yield array_shift($buffer);
            }
        }

        yield from $buffer;
    }

    private function toLocalLowerBound(int $position): int
    {
        if ($this->resolver === null) {
            return $position;
        }

        return $this->resolver->unixToLocal($position) - $this->resolver->getOffsetSpreadNear($position);
    }

    private function toLocalUpperBound(int $position): int
    {
        if ($this->resolver === null) {
            return $position;
        }

        return $this->resolver->unixToLocal($position) + $this->resolver->getOffsetSpreadNear($position);
    }

}
