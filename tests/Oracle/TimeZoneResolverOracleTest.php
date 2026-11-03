<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\TimeZoneResolver;
use ShipMonk\RRuleTests\Oracle\Python\PythonOracle;
use ShipMonk\RRuleTests\Oracle\Python\TzifWriter;
use function array_chunk;
use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function gmdate;
use function implode;
use function intdiv;
use function max;
use function min;
use function sprintf;

/**
 * Compares FloatingDateTime::atTimeZone() with Python zoneinfo (fold=0) for every zone and every transition
 * from 1970 to 2037: the times just before, at the start, in the middle, at the end of and just after each gap
 * and overlap, plus ordinary times.
 *
 * Both sides use the same zone data. PHP's bundled timezonedb (2023.3 in PHP 8.1.34) and the system or pip tzdata
 * that Python reads differ for zones whose rules changed since then. So the test writes the transitions that PHP
 * knows as TZif data, and Python loads exactly that data. The test then checks only the resolution rule.
 */
#[CoversClass(FloatingDateTime::class)]
#[CoversClass(TimeZoneResolver::class)]
final class TimeZoneResolverOracleTest extends TestCase
{

    private const ZONES_PER_GROUP = 40;
    private const MAX_REPORTED_MISMATCHES = 50;

    private const BEGIN = 0; // 1970-01-01T00:00:00Z
    private const END = 2_145_916_800; // 2038-01-01T00:00:00Z
    private const TZIF_MARGIN = 366 * 86_400;

    /**
     * Local times as seconds since 1970-01-01T00:00:00, read as UTC.
     */
    private const ORDINARY_TIMES = [
        0, // 1970-01-01T00:00:00
        172_065_600, // 1975-06-15T12:00:00
        474_629_400, // 1985-01-15T09:30:00
        946_684_799, // 1999-12-31T23:59:59
        962_409_600, // 2000-07-01T00:00:00
        1_325_246_400, // 2011-12-30T12:00:00
        1_767_258_000, // 2026-01-01T09:00:00
        1_784_141_130, // 2026-07-15T18:45:30
        2_145_916_799, // 2037-12-31T23:59:59
    ];

    /**
     * @param list<string> $zoneNames
     */
    #[DataProvider('provideZoneGroups')]
    public function testResolvesLikeZoneinfo(array $zoneNames): void
    {
        $points = [];
        $tzifByZone = [];

        foreach ($zoneNames as $zoneName) {
            $zone = new DateTimeZone($zoneName);
            $tzifByZone[$zoneName] = TzifWriter::fromTimeZone($zone, self::BEGIN - self::TZIF_MARGIN, self::END + self::TZIF_MARGIN);

            foreach (self::getLocalTimes($zone) as $localSeconds) {
                $points[] = ['zone' => $zone, 'local' => $localSeconds];
            }
        }

        try {
            $expected = PythonOracle::fromEnvironment()->resolveWithZoneinfo(
                array_map(static fn (array $point): array => ['zone' => $point['zone']->getName(), 'local' => gmdate('Y-m-d\TH:i:s', $point['local'])], $points),
                $tzifByZone,
            );
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $mismatches = [];

        foreach ($points as $index => ['zone' => $zone, 'local' => $localSeconds]) {
            $theirs = $expected[$index] ?? throw new LogicException('zoneinfo returned too few results');
            $ours = FloatingDateTime::fromDateTime(new DateTimeImmutable('@' . $localSeconds))->atTimeZone($zone)->getTimestamp();

            if ($ours !== $theirs) {
                $mismatches[] = sprintf(
                    '%s %s: ours %s, zoneinfo %s',
                    $zone->getName(),
                    gmdate('Y-m-d\TH:i:s', $localSeconds),
                    gmdate('Y-m-d\TH:i:s\Z', $ours),
                    gmdate('Y-m-d\TH:i:s\Z', $theirs),
                );
            }
        }

        self::assertSame(
            [],
            $mismatches,
            sprintf(
                "%d of %d local times resolve differently (first %d shown):\n%s",
                count($mismatches),
                count($points),
                self::MAX_REPORTED_MISMATCHES,
                implode("\n", array_slice($mismatches, 0, self::MAX_REPORTED_MISMATCHES)),
            ),
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideZoneGroups(): iterable
    {
        foreach (array_chunk(DateTimeZone::listIdentifiers(), self::ZONES_PER_GROUP) as $group) {
            yield sprintf('%s to %s', $group[0], $group[count($group) - 1]) => [$group];
        }
    }

    /**
     * @return list<int> local times as seconds since 1970-01-01T00:00:00, read as UTC
     */
    private static function getLocalTimes(DateTimeZone $zone): array
    {
        $localSeconds = [];
        $previousOffset = null;

        foreach ($zone->getTransitions(self::BEGIN, self::END) as $transition) {
            $offset = $transition['offset'];

            // The first item is the state at BEGIN, not a transition.
            if ($previousOffset !== null) {
                // A gap (offset increases) or an overlap (offset decreases) covers the local times from $first to $last.
                $first = $transition['ts'] + min($previousOffset, $offset);
                $last = $transition['ts'] + max($previousOffset, $offset) - 1;

                foreach ([$first - 3_600, $first - 1, $first, intdiv($first + $last, 2), $last, $last + 1, $last + 3_600] as $seconds) {
                    $localSeconds[$seconds] = true;
                }
            }

            $previousOffset = $offset;
        }

        foreach (self::ORDINARY_TIMES as $seconds) {
            $localSeconds[$seconds] = true;
        }

        return array_keys($localSeconds);
    }

}
