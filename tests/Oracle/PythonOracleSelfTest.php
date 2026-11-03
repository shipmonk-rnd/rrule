<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRuleTests\Oracle\Python\PythonOracle;
use ShipMonk\RRuleTests\Oracle\Python\TzifWriter;
use function gmmktime;

/**
 * Checks the Python oracle scripts against known RFC 5545 results.
 */
#[CoversNothing]
final class PythonOracleSelfTest extends TestCase
{

    private const POINTS = [
        ['zone' => 'Europe/Prague', 'local' => '2026-10-25T02:30:00'], // overlap: the first occurrence, CEST
        ['zone' => 'Europe/Prague', 'local' => '2026-03-29T02:30:00'], // gap: the offset before the gap, CET
        ['zone' => 'America/New_York', 'local' => '2007-03-11T02:30:00'], // RFC 5545 section 3.3.5 example: EST
        ['zone' => 'America/New_York', 'local' => '2007-11-04T01:30:00'], // overlap: EDT
        ['zone' => 'Australia/Lord_Howe', 'local' => '2026-10-04T02:15:00'], // 30-minute gap: +10:30
    ];

    public function testZoneinfoResolvesByZoneName(): void
    {
        self::assertSame(self::getExpectedInstants(), self::resolve([]));
    }

    public function testZoneinfoResolvesWithGivenZoneData(): void
    {
        $tzifByZone = [];

        foreach (['Europe/Prague', 'America/New_York', 'Australia/Lord_Howe'] as $zone) {
            $tzifByZone[$zone] = TzifWriter::fromTimeZone(new DateTimeZone($zone), 946_684_800, 2_145_916_800);
        }

        self::assertSame(self::getExpectedInstants(), self::resolve($tzifByZone));
    }

    public function testDateutilExpands(): void
    {
        try {
            $results = PythonOracle::fromEnvironment()->expandWithDateutil([
                ['recurrence' => "DTSTART;TZID=Europe/Prague:20260328T023000\nRRULE:FREQ=DAILY;COUNT=3", 'limit' => 10],
                ['recurrence' => "DTSTART;VALUE=DATE:20260131\nRRULE:FREQ=MONTHLY;COUNT=4", 'limit' => 10],
                ['recurrence' => "DTSTART:20260101T090000Z\nRRULE:FREQ=DAILY;INTERVAL=2;UNTIL=20260107T090000Z", 'limit' => 10],
                ['recurrence' => "DTSTART:20260101T090000\nRRULE:FREQ=WEEKLY;BYDAY=TU,TH;COUNT=4\nEXDATE:20260106T090000", 'limit' => 10],
                ['recurrence' => "DTSTART;VALUE=DATE:20241018\nRRULE:FREQ=DAILY;BYMINUTE=1,2,3,4;INTERVAL=2;COUNT=3", 'limit' => 10],
                ['recurrence' => "DTSTART:20260101T000000\nRRULE:FREQ=SECONDLY", 'limit' => 3],
                ['recurrence' => "DTSTART:20260131T100000Z\nRRULE:RSCALE=GREGORIAN;FREQ=MONTHLY;SKIP=BACKWARD", 'limit' => 3],
            ]);
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertSame(
            [
                ['20260328T023000', '20260329T023000', '20260330T023000'],
                ['20260131', '20260331', '20260531', '20260731'],
                ['20260101T090000Z', '20260103T090000Z', '20260105T090000Z', '20260107T090000Z'],
                ['20260101T090000', '20260108T090000', '20260113T090000'], // EXDATE applies after COUNT
                ['20241018T000100', '20241018T000200', '20241018T000300'], // dateutil has no DATE values
                ['20260101T000000', '20260101T000001', '20260101T000002'],
                "ValueError: unknown parameter 'RSCALE'",
            ],
            $results,
        );
    }

    /**
     * @param array<string, string> $tzifByZone
     * @return list<int>
     */
    private static function resolve(array $tzifByZone): array
    {
        try {
            return PythonOracle::fromEnvironment()->resolveWithZoneinfo(self::POINTS, $tzifByZone);
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    /**
     * @return list<int>
     */
    private static function getExpectedInstants(): array
    {
        return [
            (int) gmmktime(0, 30, 0, 10, 25, 2026),
            (int) gmmktime(1, 30, 0, 3, 29, 2026),
            (int) gmmktime(7, 30, 0, 3, 11, 2007),
            (int) gmmktime(5, 30, 0, 11, 4, 2007),
            (int) gmmktime(15, 45, 0, 10, 3, 2026),
        ];
    }

}
