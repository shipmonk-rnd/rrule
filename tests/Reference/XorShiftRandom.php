<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Reference;

use LogicException;
use function count;

/**
 * A small deterministic PRNG (xorshift64*), so that generated test cases are the same on every PHP version.
 */
final class XorShiftRandom
{

    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed * 2_654_435_761 + 1;
    }

    /**
     * @return int<0, max>
     */
    public function next(): int
    {
        $x = $this->state;
        $x ^= $x << 13;
        $x ^= ($x >> 7) & 0x01FF_FFFF_FFFF_FFFF;
        $x ^= $x << 17;
        $this->state = $x;

        return $x & 0x7FFF_FFFF_FFFF_FFFF;
    }

    public function between(
        int $min,
        int $max,
    ): int
    {
        return $min + $this->next() % ($max - $min + 1);
    }

    /**
     * @param int<0, 100> $percent
     */
    public function chance(int $percent): bool
    {
        return $this->between(1, 100) <= $percent;
    }

    /**
     * @param non-empty-list<V> $values
     * @return V
     *
     * @template V
     */
    public function pick(array $values): mixed
    {
        return $values[$this->next() % count($values)] ?? throw new LogicException('Unreachable');
    }

}
