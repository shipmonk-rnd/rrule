<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use function array_column;
use function array_reverse;
use function max;
use function min;
use const PHP_INT_MAX;

/**
 * Converts between local wall-clock time and Unix time for one time zone, as RFC 5545 section 3.3.5 requires:
 * - a local time that occurs twice (DST overlap) resolves to its first occurrence;
 * - a local time that does not exist (DST gap) uses the UTC offset from before the gap.
 *
 * PHP resolves overlaps inconsistently (the result depends on the zone and on the earlier state of the DateTime
 * object), so this class reads the transitions and resolves the time on its own.
 *
 * @internal
 */
final class TimeZoneResolver
{

    /**
     * Transitions are loaded per year, with this margin on both sides. UTC offsets are less than 26 hours apart.
     */
    private const WINDOW_MARGIN = 3 * Civil::SECONDS_PER_DAY;

    /**
     * The offset of a zone that has no transitions: UTC, or a zone given as an offset (+02:00) or abbreviation (CEST).
     */
    private readonly ?int $fixedOffset;

    /**
     * @var array<int, non-empty-list<array{int, int}>> year => segments [Unix start, UTC offset]
     */
    private array $segmentsByYear = [];

    public function __construct(
        private readonly DateTimeZone $zone,
    )
    {
        $this->fixedOffset = $zone->getName() === 'UTC' || $zone->getLocation() === false
            ? $zone->getOffset(new DateTimeImmutable('@0'))
            : null;
    }

    public function localToUnix(int $localSeconds): int
    {
        if ($this->fixedOffset !== null) {
            return $localSeconds - $this->fixedOffset;
        }

        $segments = $this->getSegments($localSeconds);

        foreach ($segments as $index => [$start, $offset]) {
            $candidate = $localSeconds - $offset;

            if ($candidate >= $start && $candidate < ($segments[$index + 1][0] ?? PHP_INT_MAX)) {
                return $candidate; // the first match is the earliest instant: the first occurrence of a repeated time
            }
        }

        // The local time is in a gap: use the offset in force before the transition that created the gap.
        foreach ($segments as $index => [, $offset]) {
            $next = $segments[$index + 1] ?? null;

            if ($next !== null && $localSeconds < $next[0] + $next[1]) {
                return $localSeconds - $offset;
            }
        }

        throw new LogicException('Local time matches no time zone segment');
    }

    public function unixToLocal(int $unixSeconds): int
    {
        if ($this->fixedOffset !== null) {
            return $unixSeconds + $this->fixedOffset;
        }

        foreach (array_reverse($this->getSegments($unixSeconds)) as [$start, $offset]) {
            if ($start <= $unixSeconds) {
                return $unixSeconds + $offset;
            }
        }

        throw new LogicException('Instant precedes the time zone segments');
    }

    /**
     * The length of the DST gap that the given local time is in or follows by less than the gap length; 0 when
     * there is none. Resolved instants grow with the local time, except here: a local time in a gap resolves with
     * the offset from before the gap, so its instant can come after the instant of a later local time, by less
     * than the gap length.
     */
    public function getGapAround(int $localSeconds): int
    {
        if ($this->fixedOffset !== null) {
            return 0;
        }

        $gap = 0;
        $previousOffset = null;

        foreach ($this->getSegments($localSeconds) as [$start, $offset]) {
            if ($previousOffset !== null && $offset > $previousOffset) {
                $length = $offset - $previousOffset;
                $gapStart = $start + $previousOffset;

                if ($localSeconds >= $gapStart && $localSeconds < $gapStart + 2 * $length) {
                    $gap = max($gap, $length);
                }
            }

            $previousOffset = $offset;
        }

        return $gap;
    }

    /**
     * The difference between the largest and the smallest UTC offset around the given time.
     */
    public function getOffsetSpreadNear(int $seconds): int
    {
        if ($this->fixedOffset !== null) {
            return 0;
        }

        $offsets = array_column($this->getSegments($seconds), 1);

        return max($offsets) - min($offsets);
    }

    /**
     * @return non-empty-list<array{int, int}>
     */
    private function getSegments(int $seconds): array
    {
        return $this->getSegmentsOfYear(self::yearOf($seconds));
    }

    /**
     * @return non-empty-list<array{int, int}>
     */
    private function getSegmentsOfYear(int $year): array
    {
        if (!isset($this->segmentsByYear[$year])) {
            $begin = Civil::firstDayOfYear($year) * Civil::SECONDS_PER_DAY - self::WINDOW_MARGIN;
            $end = Civil::firstDayOfYear($year + 1) * Civil::SECONDS_PER_DAY + self::WINDOW_MARGIN;
            $segments = [[$begin, $this->zone->getOffset(new DateTimeImmutable('@' . $begin))]];
            $transitions = $this->zone->getTransitions($begin, $end);

            foreach ($transitions as $index => $transition) {
                if ($index > 0) {
                    $segments[] = [$transition['ts'], $transition['offset']];
                }
            }

            $this->segmentsByYear[$year] = $segments;
        }

        return $this->segmentsByYear[$year];
    }

    private static function yearOf(int $seconds): int
    {
        return Civil::civilFromDays(Civil::floorDiv($seconds, Civil::SECONDS_PER_DAY))[0];
    }

}
