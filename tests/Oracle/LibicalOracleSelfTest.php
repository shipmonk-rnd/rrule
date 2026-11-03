<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRuleTests\Oracle\Libical\LibicalException;
use ShipMonk\RRuleTests\Oracle\Libical\LibicalOracle;

/**
 * Checks the libical binding and the libical builds in the oracle image against known RFC 5545 / RFC 7529 results.
 */
#[CoversNothing]
final class LibicalOracleSelfTest extends TestCase
{

    /**
     * @param list<string>      $expectedLocal
     * @param list<string>|null $expectedUtc
     */
    #[DataProvider('provideExpansions')]
    public function testExpands(
        string $libraryPath,
        string $dtStart,
        string $rrule,
        array $expectedLocal,
        ?array $expectedUtc,
    ): void
    {
        $oracle = self::createOracle($libraryPath);
        $start = DtStart::fromString($dtStart);

        self::assertSame($expectedLocal, $oracle->expand($start, $rrule, 10));

        if ($expectedUtc !== null) {
            self::assertSame($expectedUtc, $oracle->expandToUtc($start, $rrule, 10));
        }
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>, list<string>|null}>
     */
    public static function provideExpansions(): iterable
    {
        foreach (self::provideLibraries() as $build => [$libraryPath]) {
            yield "$build: RFC 5545 second-to-last Monday" => [
                $libraryPath,
                'TZID=America/New_York:19970922T090000',
                'FREQ=MONTHLY;COUNT=6;BYDAY=-2MO',
                ['19970922T090000', '19971020T090000', '19971117T090000', '19971222T090000', '19980119T090000', '19980216T090000'],
                ['19970922T130000Z', '19971020T130000Z', '19971117T140000Z', '19971222T140000Z', '19980119T140000Z', '19980216T140000Z'],
            ];

            yield "$build: DATE start skips invalid dates" => [
                $libraryPath,
                '20260131',
                'FREQ=MONTHLY;COUNT=4',
                ['20260131', '20260331', '20260531', '20260731'],
                null,
            ];

            yield "$build: floating start with UNTIL" => [
                $libraryPath,
                '20260101T090000',
                'FREQ=WEEKLY;BYDAY=TU,TH;UNTIL=20260113T090000',
                ['20260101T090000', '20260106T090000', '20260108T090000', '20260113T090000'],
                null,
            ];

            yield "$build: UTC start" => [
                $libraryPath,
                '20260101T090000Z',
                'FREQ=DAILY;INTERVAL=2;UNTIL=20260107T090000Z',
                ['20260101T090000Z', '20260103T090000Z', '20260105T090000Z', '20260107T090000Z'],
                ['20260101T090000Z', '20260103T090000Z', '20260105T090000Z', '20260107T090000Z'],
            ];

            yield "$build: RFC 7529 SKIP=BACKWARD" => [
                $libraryPath,
                '20260131T100000Z',
                'RSCALE=GREGORIAN;FREQ=MONTHLY;BYMONTHDAY=31;SKIP=BACKWARD;COUNT=5',
                ['20260131T100000Z', '20260228T100000Z', '20260331T100000Z', '20260430T100000Z', '20260531T100000Z'],
                null,
            ];

            yield "$build: RFC 7529 SKIP=FORWARD after FREQ" => [
                $libraryPath,
                '20260131T100000Z',
                'FREQ=MONTHLY;BYMONTHDAY=31;RSCALE=GREGORIAN;SKIP=FORWARD;COUNT=5',
                ['20260131T100000Z', '20260301T100000Z', '20260331T100000Z', '20260501T100000Z', '20260531T100000Z'],
                null,
            ];

            // RFC 5545 section 3.3.5 with erratum 4271: the first occurrence of 02:30 is CEST (00:30Z).
            yield "$build: DAILY across the Prague fall-back overlap" => [
                $libraryPath,
                'TZID=Europe/Prague:20261024T023000',
                'FREQ=DAILY;COUNT=3',
                ['20261024T023000', '20261025T023000', '20261026T023000'],
                ['20261024T003000Z', '20261025T003000Z', '20261026T013000Z'],
            ];

            // The nonexistent 02:30 uses the offset from before the gap (CET, 01:30Z); the next day is 02:30 again.
            yield "$build: DAILY across the Prague spring gap does not drift" => [
                $libraryPath,
                'TZID=Europe/Prague:20260328T023000',
                'FREQ=DAILY;COUNT=3',
                ['20260328T023000', '20260329T023000', '20260330T023000'],
                ['20260328T013000Z', '20260329T013000Z', '20260330T003000Z'],
            ];

            // libical issue #1450, fixed by PR #1467 (not in v4.0.7): ICU builds kept 03:30 after the gap.
            yield "$build: WEEKLY across the Berlin spring gap does not drift" => [
                $libraryPath,
                'TZID=Europe/Berlin:20260322T023000',
                'FREQ=WEEKLY;COUNT=4',
                ['20260322T023000', '20260329T023000', '20260405T023000', '20260412T023000'],
                ['20260322T013000Z', '20260329T013000Z', '20260405T003000Z', '20260412T003000Z'],
            ];

            yield "$build: half-hour gap in Australia/Lord_Howe" => [
                $libraryPath,
                'TZID=Australia/Lord_Howe:20261003T021500',
                'FREQ=DAILY;COUNT=3',
                ['20261003T021500', '20261004T021500', '20261005T021500'],
                ['20261002T154500Z', '20261003T154500Z', '20261004T151500Z'],
            ];
        }
    }

    /**
     * Sub-daily rules across a gap differ by build: without ICU libical steps in wall-clock time (and 02:00 does not
     * exist), with ICU it steps in elapsed time. RFC 5545 does not say which is right.
     */
    public function testHourlyAcrossGapDependsOnBuild(): void
    {
        $start = DtStart::fromString('TZID=Europe/Prague:20260329T010000');
        $rule = 'FREQ=HOURLY;COUNT=4';

        $withoutIcu = self::createOracle(LibicalOracle::DEFAULT_LIBRARY_PATH);
        self::assertFalse($withoutIcu->hasIcu());
        self::assertSame(['20260329T010000', '20260329T020000', '20260329T030000', '20260329T040000'], $withoutIcu->expand($start, $rule, 10));
        self::assertSame(['20260329T000000Z', '20260329T010000Z', '20260329T010000Z', '20260329T020000Z'], $withoutIcu->expandToUtc($start, $rule, 10));

        $withIcu = self::createOracle(LibicalOracle::ICU_LIBRARY_PATH);
        self::assertTrue($withIcu->hasIcu());
        self::assertSame(['20260329T010000', '20260329T030000', '20260329T040000', '20260329T050000'], $withIcu->expand($start, $rule, 10));
        self::assertSame(['20260329T000000Z', '20260329T010000Z', '20260329T020000Z', '20260329T030000Z'], $withIcu->expandToUtc($start, $rule, 10));
    }

    #[DataProvider('provideLibraries')]
    public function testLimitStopsInfiniteRule(string $libraryPath): void
    {
        $oracle = self::createOracle($libraryPath);

        self::assertCount(25, $oracle->expand(DtStart::fromString('20260101T000000'), 'FREQ=SECONDLY', 25));
        self::assertSame([], $oracle->expand(DtStart::fromString('20260101T000000'), 'FREQ=DAILY', 0));
    }

    /**
     * @param non-empty-string $expectedError
     */
    #[DataProvider('provideInvalidInputs')]
    public function testReportsLibicalErrors(
        string $libraryPath,
        string $dtStart,
        string $rrule,
        string $expectedError,
    ): void
    {
        $oracle = self::createOracle($libraryPath);

        try {
            $oracle->expand(DtStart::fromString($dtStart), $rrule, 10);
            self::fail('LibicalException expected');
        } catch (LibicalException $e) {
            self::assertStringStartsWith($expectedError, $e->libicalError);
            self::assertStringContainsString($rrule, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, non-empty-string}>
     */
    public static function provideInvalidInputs(): iterable
    {
        foreach (self::provideLibraries() as $build => [$libraryPath]) {
            yield "$build: unknown FREQ" => [$libraryPath, '20260101T090000', 'FREQ=FORTNIGHTLY', 'MALFORMEDDATA'];
            yield "$build: COUNT and UNTIL" => [$libraryPath, '20260101T090000', 'FREQ=DAILY;COUNT=2;UNTIL=20260105T000000', 'MALFORMEDDATA'];
            yield "$build: BYMONTHDAY with WEEKLY" => [$libraryPath, '20260101T090000', 'FREQ=WEEKLY;BYMONTHDAY=1', 'MALFORMEDDATA'];
            yield "$build: BYYEARDAY with DAILY" => [$libraryPath, '20260101T090000', 'FREQ=DAILY;BYYEARDAY=1', 'MALFORMEDDATA'];
            yield "$build: unsupported combination" => [$libraryPath, '20241230', 'FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=30;BYWEEKNO=1;UNTIL=20320101', 'UNIMPLEMENTED'];
        }
    }

    public function testReportsUnknownTimeZone(): void
    {
        $oracle = self::createOracle(LibicalOracle::DEFAULT_LIBRARY_PATH);

        $this->expectException(LibicalException::class);
        $this->expectExceptionMessage('Mars/Olympus_Mons');

        $oracle->expand(DtStart::fromString('TZID=Mars/Olympus_Mons:20260101T090000'), 'FREQ=DAILY', 10);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLibraries(): iterable
    {
        yield 'without ICU' => [LibicalOracle::DEFAULT_LIBRARY_PATH];
        yield 'with ICU' => [LibicalOracle::ICU_LIBRARY_PATH];
    }

    private static function createOracle(string $libraryPath): LibicalOracle
    {
        try {
            return new LibicalOracle($libraryPath);
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

}
