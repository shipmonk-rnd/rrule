<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use function count;
use function max;
use function min;

/**
 * Converts between local wall-clock time and Unix time for one time zone, as RFC 5545 section 3.3.5 requires.
 *
 * - A local time that occurs twice (DST overlap) resolves to its first occurrence.
 * - A local time that does not exist (DST gap) uses the UTC offset from before the gap.
 *
 * PHP itself resolves overlaps inconsistently (the result depends on the zone and on the earlier state of the
 * DateTime object), so this class reads the transitions and resolves the time on its own.
 *
 * @internal
 */
final class TimeZoneResolver
{

    /**
     * Transitions are loaded per local year, with this margin on both sides. Offsets are at most 26 hours apart.
     */
    private const WINDOW_MARGIN = 3 * Civil::SECONDS_PER_DAY;

    private readonly bool $fixedUtc;

    /**
     * @var array<int, array{list<int>, list<int>}> year => [segment start Unix times, segment offsets]
     */
    private array $segmentsByYear = [];

    /**
     * @var array<int, int> year => largest forward offset jump (gap length) near that year
     */
    private array $maxGapByYear = [];

    public function __construct(
        private readonly DateTimeZone $zone,
    )
    {
        $this->fixedUtc = $zone->getName() === 'UTC';
    }

    public function localToUnix(int $localSeconds): int
    {
        if ($this->fixedUtc) {
            return $localSeconds;
        }

        [$starts, $offsets] = $this->getSegments($localSeconds);
        $segmentCount = count($starts);
        $resolved = null;

        for ($i = 0; $i < $segmentCount; $i++) {
            $candidate = $localSeconds - $offsets[$i];

            if ($candidate >= $starts[$i] && ($i + 1 === $segmentCount || $candidate < $starts[$i + 1])) {
                $resolved = $resolved === null ? $candidate : min($resolved, $candidate);
            }
        }

        if ($resolved !== null) {
            return $resolved;
        }

        // The local time is in a gap: use the offset in force before the transition that created the gap.
        for ($i = 1; $i < $segmentCount; $i++) {
            if ($localSeconds >= $starts[$i] + $offsets[$i - 1] && $localSeconds < $starts[$i] + $offsets[$i]) {
                return $localSeconds - $offsets[$i - 1];
            }
        }

        throw new LogicException('Local time matches no time zone segment');
    }

    public function unixToLocal(int $unixSeconds): int
    {
        if ($this->fixedUtc) {
            return $unixSeconds;
        }

        [$starts, $offsets] = $this->getSegments($unixSeconds);
        $offset = $offsets[0];

        foreach ($starts as $i => $start) {
            if ($start > $unixSeconds) {
                break;
            }

            $offset = $offsets[$i];
        }

        return $unixSeconds + $offset;
    }

    /**
     * The largest forward offset change (the length of the longest gap) within a year around the given time.
     * Resolved Unix times are increasing in local time, except inside a gap, where they can go back by at most
     * this amount.
     */
    public function getMaxGapNear(int $localSeconds): int
    {
        if ($this->fixedUtc) {
            return 0;
        }

        $year = self::yearOf($localSeconds);

        if (!isset($this->maxGapByYear[$year])) {
            $maxGap = 0;

            foreach ([$year - 1, $year, $year + 1] as $nearYear) {
                [, $offsets] = $this->getSegmentsOfYear($nearYear);

                for ($i = 1, $count = count($offsets); $i < $count; $i++) {
                    $maxGap = max($maxGap, $offsets[$i] - $offsets[$i - 1]);
                }
            }

            $this->maxGapByYear[$year] = $maxGap;
        }

        return $this->maxGapByYear[$year];
    }

    /**
     * @return array{list<int>, list<int>}
     */
    private function getSegments(int $seconds): array
    {
        return $this->getSegmentsOfYear(self::yearOf($seconds));
    }

    /**
     * @return array{list<int>, list<int>}
     */
    private function getSegmentsOfYear(int $year): array
    {
        if (!isset($this->segmentsByYear[$year])) {
            $begin = Civil::daysFromCivil($year, 1, 1) * Civil::SECONDS_PER_DAY - self::WINDOW_MARGIN;
            $end = Civil::daysFromCivil($year + 1, 1, 1) * Civil::SECONDS_PER_DAY + self::WINDOW_MARGIN;
            $starts = [];
            $offsets = [];

            foreach ($this->zone->getTransitions($begin, $end) ?: [] as $index => $transition) {
                $starts[] = $index === 0 ? $begin : $transition['ts'];
                $offsets[] = $transition['offset'];
            }

            if ($starts === []) {
                $starts[] = $begin;
                $offsets[] = $this->zone->getOffset(new DateTimeImmutable('@' . $begin));
            }

            $this->segmentsByYear[$year] = [$starts, $offsets];
        }

        return $this->segmentsByYear[$year];
    }

    private static function yearOf(int $seconds): int
    {
        return Civil::civilFromDays(Civil::floorDiv($seconds, Civil::SECONDS_PER_DAY))[0];
    }

}
