<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Internal;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\Internal\TimeZoneResolver;

#[CoversClass(TimeZoneResolver::class)]
final class TimeZoneResolverTest extends TestCase
{

    /**
     * @param string $local    Y-m-d H:i:s wall-clock time
     * @param string $expected the expected instant in ISO 8601 with offset
     */
    #[DataProvider('provideLocalTimes')]
    public function testLocalToUnix(
        string $zone,
        string $local,
        string $expected,
    ): void
    {
        $resolver = new TimeZoneResolver(new DateTimeZone($zone));
        $unix = $resolver->localToUnix(self::local($local));

        self::assertSame((new DateTimeImmutable($expected))->getTimestamp(), $unix, "$zone $local");
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideLocalTimes(): iterable
    {
        // RFC 5545 section 3.3.5 examples
        yield 'New York gap uses the offset before the gap' => ['America/New_York', '2007-03-11 02:30:00', '2007-03-11T03:30:00-04:00'];
        yield 'New York overlap is the first occurrence' => ['America/New_York', '2007-11-04 01:30:00', '2007-11-04T01:30:00-04:00'];

        yield 'Prague overlap (PHP resolves this to CET)' => ['Europe/Prague', '2026-10-25 02:30:00', '2026-10-25T02:30:00+02:00'];
        yield 'Prague overlap start' => ['Europe/Prague', '2026-10-25 02:00:00', '2026-10-25T02:00:00+02:00'];
        yield 'Prague after overlap' => ['Europe/Prague', '2026-10-25 03:00:00', '2026-10-25T03:00:00+01:00'];
        yield 'Prague before overlap' => ['Europe/Prague', '2026-10-25 01:59:59', '2026-10-25T01:59:59+02:00'];
        yield 'Prague gap start' => ['Europe/Prague', '2026-03-29 02:00:00', '2026-03-29T03:00:00+02:00'];
        yield 'Prague gap end' => ['Europe/Prague', '2026-03-29 02:59:59', '2026-03-29T03:59:59+02:00'];
        yield 'Prague after gap' => ['Europe/Prague', '2026-03-29 03:00:00', '2026-03-29T03:00:00+02:00'];
        yield 'Prague winter' => ['Europe/Prague', '2026-01-15 12:00:00', '2026-01-15T12:00:00+01:00'];
        yield 'Lord Howe 30-minute gap' => ['Australia/Lord_Howe', '2026-10-04 02:15:00', '2026-10-04T02:45:00+11:00'];
        yield 'Lord Howe 30-minute overlap' => ['Australia/Lord_Howe', '2026-04-05 01:45:00', '2026-04-05T01:45:00+11:00'];
        yield 'Apia skipped day' => ['Pacific/Apia', '2011-12-30 12:00:00', '2011-12-31T12:00:00+14:00'];
        yield 'Sao Paulo midnight gap' => ['America/Sao_Paulo', '2018-11-04 00:30:00', '2018-11-04T01:30:00-02:00'];
        yield 'UTC' => ['UTC', '2026-06-01 12:00:00', '2026-06-01T12:00:00+00:00'];
        yield 'Year 9999' => ['Europe/Prague', '9999-07-01 12:00:00', '9999-07-01T12:00:00+02:00'];
        yield 'fixed offset zone' => ['+02:00', '2026-03-29 02:30:00', '2026-03-29T02:30:00+02:00'];
        yield 'abbreviation zone' => ['CEST', '2026-01-15 12:00:00', '2026-01-15T12:00:00+02:00'];
        yield 'Kolkata (no DST)' => ['Asia/Kolkata', '2026-03-29 02:30:00', '2026-03-29T02:30:00+05:30'];
    }

    #[DataProvider('provideInstants')]
    public function testUnixToLocal(
        string $zone,
        string $instant,
        string $expectedLocal,
    ): void
    {
        $resolver = new TimeZoneResolver(new DateTimeZone($zone));

        self::assertSame(self::local($expectedLocal), $resolver->unixToLocal((new DateTimeImmutable($instant))->getTimestamp()));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideInstants(): iterable
    {
        yield 'first pass of the overlap' => ['Europe/Prague', '2026-10-25T00:30:00Z', '2026-10-25 02:30:00'];
        yield 'second pass of the overlap' => ['Europe/Prague', '2026-10-25T01:30:00Z', '2026-10-25 02:30:00'];
        yield 'transition instant' => ['Europe/Prague', '2026-10-25T01:00:00Z', '2026-10-25 02:00:00'];
        yield 'just before transition' => ['Europe/Prague', '2026-10-25T00:59:59Z', '2026-10-25 02:59:59'];
        yield 'spring' => ['Europe/Prague', '2026-03-29T01:00:00Z', '2026-03-29 03:00:00'];
        yield 'UTC' => ['UTC', '2026-03-29T01:00:00Z', '2026-03-29 01:00:00'];
        yield 'January 1 in the window of the previous year' => ['Europe/Prague', '2025-12-31T23:30:00Z', '2026-01-01 00:30:00'];
    }

    public function testFixedOffsetZones(): void
    {
        foreach (['+02:00' => 7200, 'CEST' => 7200, '-05:30' => -19_800] as $name => $offset) {
            $resolver = new TimeZoneResolver(new DateTimeZone($name));
            $time = self::local('2026-03-29 02:30:00');

            self::assertSame($time - $offset, $resolver->localToUnix($time), $name);
            self::assertSame($time + $offset, $resolver->unixToLocal($time), $name);
            self::assertSame(0, $resolver->getMaxGapNear($time));
            self::assertSame(0, $resolver->getOffsetSpreadNear($time));
        }
    }

    public function testGapAndSpread(): void
    {
        $prague = new TimeZoneResolver(new DateTimeZone('Europe/Prague'));
        $kolkata = new TimeZoneResolver(new DateTimeZone('Asia/Kolkata'));
        $lordHowe = new TimeZoneResolver(new DateTimeZone('Australia/Lord_Howe'));
        $utc = new TimeZoneResolver(new DateTimeZone('UTC'));
        $time = self::local('2026-06-01 00:00:00');

        self::assertSame(3600, $prague->getMaxGapNear($time));
        self::assertSame(3600, $prague->getOffsetSpreadNear($time));
        self::assertSame(0, $kolkata->getMaxGapNear($time));
        self::assertSame(0, $kolkata->getOffsetSpreadNear($time));
        self::assertSame(1800, $lordHowe->getMaxGapNear($time));
        self::assertSame(1800, $lordHowe->getOffsetSpreadNear($time));
        self::assertSame(0, $utc->getMaxGapNear($time));
        self::assertSame(0, $utc->getOffsetSpreadNear($time));

        $apia = new TimeZoneResolver(new DateTimeZone('Pacific/Apia'));
        self::assertSame(86_400, $apia->getMaxGapNear(self::local('2011-12-30 00:00:00'))); // -10:00 to +14:00

        // Moscow moved from +04:00 to +03:00 on 2014-10-26, its only transition that year
        $moscow = new TimeZoneResolver(new DateTimeZone('Europe/Moscow'));
        self::assertSame(0, $moscow->getMaxGapNear(self::local('2014-06-01 00:00:00')));
        self::assertSame(3600, $moscow->getOffsetSpreadNear(self::local('2014-06-01 00:00:00')));
    }

    public function testEveryLocalTimeAroundAllTransitionsOfAZoneRoundTrips(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $resolver = new TimeZoneResolver($zone);

        foreach ($zone->getTransitions(0, 2_000_000_000) as $transition) {
            for ($unix = $transition['ts'] - 7200; $unix <= $transition['ts'] + 7200; $unix += 900) {
                $local = $resolver->unixToLocal($unix);
                $resolved = $resolver->localToUnix($local);

                // a local time maps back to the earliest instant with that local time
                self::assertLessThanOrEqual($unix, $resolved);
                self::assertSame($local, $resolver->unixToLocal($resolved));
            }
        }
    }

    private static function local(string $dateTime): int
    {
        return (new DateTimeImmutable($dateTime . ' UTC'))->getTimestamp();
    }

}
