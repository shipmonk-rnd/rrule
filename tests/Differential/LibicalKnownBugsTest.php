<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\DtStartPolicy;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRuleTests\Differential\Libical\LibicalAdapter;
use function sprintf;

/**
 * The libical bugs that the differential tests found, with the result that RFC 5545 and RFC 7529 require.
 * When a libical update fixes a bug, its first assertion fails: then remove the case and the claim in the README.
 */
#[CoversNothing]
final class LibicalKnownBugsTest extends TestCase
{

    /**
     * @param list<string> $libicalResult
     * @param list<string> $rfcResult
     */
    #[DataProvider('provideBugs')]
    public function testLibicalDiffersFromTheRfc(
        string $start,
        string $rule,
        array $libicalResult,
        array $rfcResult,
    ): void
    {
        try {
            $libical = new LibicalAdapter(LibicalAdapter::DEFAULT_LIBRARY_PATH);
        } catch (AdapterUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $recurrence = Recurrence::fromString("DTSTART:$start\nRRULE:$rule")->withDtStartPolicy(DtStartPolicy::OnlyIfMatching);
        self::assertTrue($recurrence->isFloating());
        $ours = [];

        foreach ($recurrence->take(5) as $occurrence) {
            $ours[] = self::format($occurrence);
        }

        self::assertSame($libicalResult, $libical->expand(DtStart::fromString($start), $rule, 5), 'libical changed: check whether it fixed the bug');
        self::assertSame($rfcResult, $ours);
    }

    /**
     * @return iterable<string, array{string, string, list<string>, list<string>}>
     */
    public static function provideBugs(): iterable
    {
        // one candidate per day, so BYSETPOS=2 selects nothing; libical returns dates centuries later
        yield 'DAILY with BYSETPOS beyond the period' => [
            '20260101T090000',
            'FREQ=DAILY;BYHOUR=9;BYSETPOS=2',
            ['22991018T090000', '25730803T090000'],
            [],
        ];

        // libical skips the matches that are left in the first day, hour or minute
        yield 'HOURLY skips the rest of the first day' => [
            '20260101T091000',
            'FREQ=HOURLY;BYHOUR=23',
            ['20260102T231000', '20260103T231000', '20260104T231000', '20260105T231000', '20260106T231000'],
            ['20260101T231000', '20260102T231000', '20260103T231000', '20260104T231000', '20260105T231000'],
        ];
        yield 'MINUTELY skips the rest of the first hour' => [
            '20260101T000000',
            'FREQ=MINUTELY;BYMINUTE=55',
            ['20260101T015500', '20260101T025500', '20260101T035500', '20260101T045500', '20260101T055500'],
            ['20260101T005500', '20260101T015500', '20260101T025500', '20260101T035500', '20260101T045500'],
        ];
        yield 'SECONDLY skips the rest of the first minute' => [
            '20260101T125400',
            'FREQ=SECONDLY;BYSECOND=35',
            ['20260101T125535', '20260101T125635', '20260101T125735', '20260101T125835', '20260101T125935'],
            ['20260101T125435', '20260101T125535', '20260101T125635', '20260101T125735', '20260101T125835'],
        ];

        // week -53 exists only in a year with 53 weeks, where it is week 1; libical takes the last week of every year
        yield 'BYWEEKNO=-53' => [
            '20260101T000000',
            'FREQ=YEARLY;BYWEEKNO=-53',
            ['20260101T000000', '20261231T000000', '20271230T000000', '20281228T000000', '20291227T000000'],
            ['20260101T000000', '20320101T000000', '20370101T000000', '20430101T000000', '20480102T000000'],
        ];

        // February 31 moves to March 1, a Monday in 2027 and in 2028 (April 31 to May 1); libical drops moved dates when BYDAY is present
        yield 'SKIP=FORWARD with BYDAY' => [
            '20260131T000000',
            'RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=31;BYDAY=MO;SKIP=FORWARD',
            ['20260831T000000', '20270531T000000', '20280131T000000', '20280731T000000', '20291231T000000'],
            ['20260831T000000', '20270301T000000', '20270531T000000', '20280131T000000', '20280501T000000'],
        ];
    }

    private static function format(FloatingDateTime $value): string
    {
        return sprintf('%04d%02d%02dT%02d%02d%02d', $value->year, $value->month, $value->day, $value->hour, $value->minute, $value->second);
    }

}
