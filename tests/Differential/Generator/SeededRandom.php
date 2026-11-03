<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential\Generator;

use LogicException;
use function array_key_exists;
use function array_splice;
use function count;
use function intdiv;

/**
 * A small seeded PRNG (xoshiro128**, seeded with SplitMix32). It uses only 32-bit integer arithmetic,
 * so the sequence is the same on every PHP version and platform, unlike mt_rand() and random_int().
 */
final class SeededRandom
{

    private const MASK = 0xFFFFFFFF;

    private int $s0;

    private int $s1;

    private int $s2;

    private int $s3;

    public function __construct(int $seed)
    {
        $state = $seed & self::MASK;
        $this->s0 = self::splitMix($state);
        $this->s1 = self::splitMix($state);
        $this->s2 = self::splitMix($state);
        $this->s3 = self::splitMix($state);
    }

    /**
     * @return int<0, 4294967295>
     */
    public function nextUint32(): int
    {
        $result = self::multiply(self::rotateLeft(self::multiply($this->s1, 5), 7), 9);
        $t = ($this->s1 << 9) & self::MASK;

        $this->s2 ^= $this->s0;
        $this->s3 ^= $this->s1;
        $this->s1 ^= $this->s2;
        $this->s0 ^= $this->s3;
        $this->s2 ^= $t;
        $this->s3 = self::rotateLeft($this->s3, 11);

        /** @var int<0, 4294967295> $result */
        return $result;
    }

    /**
     * A uniformly distributed integer from $min to $max (both inclusive).
     */
    public function int(
        int $min,
        int $max,
    ): int
    {
        $range = $max - $min + 1;

        if ($range < 1 || $range > self::MASK + 1) {
            throw new LogicException('Invalid range');
        }

        $limit = intdiv(self::MASK + 1, $range) * $range;

        do {
            $value = $this->nextUint32();
        } while ($value >= $limit);

        return $min + $value % $range;
    }

    /**
     * True with the probability $percent / 100.
     */
    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @param non-empty-list<T> $items
     * @return T
     *
     * @template T
     */
    public function pick(array $items): mixed
    {
        $index = $this->int(0, count($items) - 1);

        if (!array_key_exists($index, $items)) {
            throw new LogicException('Unreachable');
        }

        return $items[$index];
    }

    /**
     * Picks a key with a probability proportional to its weight.
     *
     * @param non-empty-array<T, positive-int> $weights
     * @return T
     *
     * @template T of array-key
     */
    public function pickWeighted(array $weights): int|string
    {
        $total = 0;

        foreach ($weights as $weight) {
            $total += $weight;
        }

        $value = $this->int(1, $total);

        foreach ($weights as $key => $weight) {
            $value -= $weight;

            if ($value <= 0) {
                return $key;
            }
        }

        throw new LogicException('Unreachable');
    }

    /**
     * Up to $count distinct items in random order.
     *
     * @param list<T> $items
     * @return list<T>
     *
     * @template T
     */
    public function sample(
        array $items,
        int $count,
    ): array
    {
        $sample = [];

        while ($count > 0 && $items !== []) {
            foreach (array_splice($items, $this->int(0, count($items) - 1), 1) as $item) {
                $sample[] = $item;
            }

            $count--;
        }

        return $sample;
    }

    /**
     * @param list<T> $items
     * @return list<T>
     *
     * @template T
     */
    public function shuffle(array $items): array
    {
        return $this->sample($items, count($items));
    }

    private static function splitMix(int &$state): int
    {
        $state = ($state + 0x9E3779B9) & self::MASK;
        $z = self::multiply($state ^ ($state >> 16), 0x85EBCA6B);
        $z = self::multiply($z ^ ($z >> 13), 0xC2B2AE35);

        return $z ^ ($z >> 16);
    }

    /**
     * The low 32 bits of $a * $b, without the float overflow of a 64-bit product.
     */
    private static function multiply(
        int $a,
        int $b,
    ): int
    {
        return ($a * ($b & 0xFFFF) + ((($a * ($b >> 16)) & 0xFFFF) << 16)) & self::MASK;
    }

    private static function rotateLeft(
        int $value,
        int $bits,
    ): int
    {
        return (($value << $bits) | ($value >> (32 - $bits))) & self::MASK;
    }

}
