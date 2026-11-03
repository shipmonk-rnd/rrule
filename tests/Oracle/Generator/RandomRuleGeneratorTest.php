<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\RecurrenceRule;
use ShipMonk\RRuleTests\Oracle\DtStartKind;
use ShipMonk\RRuleTests\Oracle\Libical\LibicalException;
use ShipMonk\RRuleTests\Oracle\Libical\LibicalOracle;
use ShipMonk\RRuleTests\Oracle\OracleUnavailableException;
use ShipMonk\RRuleTests\Oracle\Python\PythonOracle;
use function array_combine;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function sha1;
use function sprintf;
use function str_starts_with;

#[CoversClass(RandomRuleGenerator::class)]
#[CoversClass(SeededRandom::class)]
final class RandomRuleGeneratorTest extends TestCase
{

    private const CASE_COUNT = 3_000;
    private const SUB_DAILY = ['HOURLY', 'MINUTELY', 'SECONDLY'];

    public function testSameSeedGivesSameCases(): void
    {
        self::assertSame(self::generateStrings(42, 300), self::generateStrings(42, 300));
        self::assertNotSame(self::generateStrings(42, 20), self::generateStrings(43, 20));
    }

    /**
     * The fingerprint proves that the output is the same on every PHP version (CI runs PHP 8.1).
     * Update it when you change the generator on purpose.
     */
    public function testOutputIsStable(): void
    {
        $cases = self::generateStrings(1, 1_000);

        self::assertSame(
            ['20201229T090000Z FREQ=MINUTELY;UNTIL=20201229T092600Z', '0dec8b0fb2fd460103b37a1a4bf4134173fa99a5'],
            [$cases[0] ?? null, sha1(implode("\n", $cases))],
        );
    }

    public function testRulesObeyRfc5545(): void
    {
        $violations = [];

        foreach (self::generate(7, self::CASE_COUNT) as $case) {
            foreach (self::findViolations($case) as $violation) {
                $violations[] = sprintf('%s: %s', $case->toString(), $violation);
            }

            self::assertSame(explode(';', $case->rrule)[0], 'FREQ=' . $case->rule->frequency->value);
        }

        self::assertSame([], $violations);
    }

    public function testCoversAllRuleParts(): void
    {
        $features = [];

        foreach (self::generate(7, self::CASE_COUNT) as $case) {
            $features[$case->dtStart->kind->name] = true;
            $features[(string) $case->dtStart->timeZone] = true;

            foreach (self::parseParts($case->rrule) as $name => $value) {
                $features[$name] = true;

                if ($name === 'FREQ' || $name === 'SKIP') {
                    $features["$name=$value"] = true;
                }

                if (preg_match('~(^|,)-\d~', $value) === 1) {
                    $features["$name negative"] = true;
                }

                if (preg_match('~(^|,)\+\d~', $value) === 1) {
                    $features["$name plus sign"] = true;
                }

                if ($name === 'BYDAY' && preg_match('~(^|,)\+?\d+[A-Z]~', $value) === 1) {
                    $features['BYDAY ordinal'] = true;
                }

                if ($name === 'BYSECOND' && preg_match('~(^|,)60(,|$)~', $value) === 1) {
                    $features['BYSECOND=60'] = true;
                }
            }
        }

        $expected = [
            ...array_map(static fn (DtStartKind $kind): string => $kind->name, DtStartKind::cases()),
            ...RandomRuleGenerator::TIME_ZONES,
            'FREQ=SECONDLY', 'FREQ=MINUTELY', 'FREQ=HOURLY', 'FREQ=DAILY', 'FREQ=WEEKLY', 'FREQ=MONTHLY', 'FREQ=YEARLY',
            'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYDAY', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS',
            'BYMONTHDAY negative', 'BYYEARDAY negative', 'BYWEEKNO negative', 'BYSETPOS negative', 'BYDAY negative',
            'BYDAY ordinal', 'BYDAY plus sign', 'BYMONTHDAY plus sign', 'BYSECOND=60',
            'WKST', 'INTERVAL', 'COUNT', 'UNTIL', 'RSCALE', 'SKIP=OMIT', 'SKIP=BACKWARD', 'SKIP=FORWARD',
        ];

        self::assertSame([], array_values(array_filter($expected, static fn (string $feature): bool => !isset($features[$feature]))));
    }

    public function testParserAcceptsGeneratedRules(): void
    {
        foreach (self::generate(7, self::CASE_COUNT) as $case) {
            try {
                $parsed = RecurrenceRule::fromString($case->rrule);
            } catch (ParseException $e) {
                self::fail(sprintf('%s: %s', $case->toString(), $e->getMessage()));
            }

            self::assertEquals($case->rule, $parsed, $case->toString());
        }
    }

    /**
     * libical rejects some valid rules: "UNIMPLEMENTED" for some BYWEEKNO combinations, and "MALFORMEDDATA" for
     * rules that can never match (for example BYMONTH=2;BYMONTHDAY=31). Both must stay rare, and most rules must
     * produce occurrences.
     */
    public function testLibicalExpandsMostGeneratedRules(): void
    {
        try {
            $oracle = LibicalOracle::fromEnvironment();
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $caseCount = 1_000;
        $productive = 0;
        $rejected = [];

        foreach (self::generate(11, $caseCount) as $case) {
            try {
                $productive += $oracle->expand($case->dtStart, $case->rrule, 5) === [] ? 0 : 1;
            } catch (LibicalException $e) {
                $rejected[] = sprintf('%s: %s', $case->toString(), $e->libicalError);
            }
        }

        self::assertLessThan($caseCount / 50, count($rejected), implode("\n", $rejected));
        self::assertGreaterThan($caseCount * 0.85, $productive);
    }

    /**
     * dateutil parses the rules independently of libical and of our parser. It does not support RFC 7529
     * (RSCALE, SKIP) or BYSECOND=60; any other error means that the generator wrote a malformed rule.
     */
    public function testDateutilParsesGeneratedRules(): void
    {
        $cases = self::generate(13, 300);

        try {
            $results = PythonOracle::fromEnvironment()->expandWithDateutil(array_map(
                static fn (GeneratedRecurrence $case): array => ['recurrence' => $case->toRecurrenceText(), 'limit' => 5],
                $cases,
            ));
        } catch (OracleUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $unexpectedErrors = [];
        $productive = 0;

        foreach ($results as $index => $result) {
            if ($result === []) {
                continue;
            }

            if (!is_string($result)) {
                $productive++;
                continue;
            }

            if (preg_match("~^ValueError: (unknown parameter '(RSCALE|SKIP)'|second must be in 0\\.\\.59|Invalid rrule byxxx generates an empty set)~", $result) !== 1) {
                $unexpectedErrors[] = sprintf('%s: %s', ($cases[$index] ?? null)?->toString(), $result);
            }
        }

        self::assertSame([], $unexpectedErrors);
        self::assertGreaterThan(count($cases) * 0.7, $productive);
    }

    /**
     * @return list<string>
     */
    private static function findViolations(GeneratedRecurrence $case): array
    {
        preg_match_all('~(?:^|;)([A-Z]+)=~', $case->rrule, $matches);
        $names = $matches[1];

        $parts = self::parseParts($case->rrule);
        $frequency = $parts['FREQ'] ?? '';
        $byParts = array_filter(array_keys($parts), static fn (string $name): bool => str_starts_with($name, 'BY') && $name !== 'BYSETPOS');
        $violations = [];

        if (($names[0] ?? null) !== 'FREQ') {
            $violations[] = 'FREQ is not the first part';
        }

        if (count($names) !== count(array_unique($names))) {
            $violations[] = 'a part occurs twice';
        }

        if (isset($parts['COUNT'], $parts['UNTIL'])) {
            $violations[] = 'COUNT and UNTIL together';
        }

        if (isset($parts['UNTIL'])) {
            $pattern = match ($case->dtStart->kind) {
                DtStartKind::Date => '~^\d{8}$~D',
                DtStartKind::Floating => '~^\d{8}T\d{6}$~D',
                DtStartKind::Utc, DtStartKind::Zoned => '~^\d{8}T\d{6}Z$~D',
            };

            if (preg_match($pattern, $parts['UNTIL']) !== 1) {
                $violations[] = 'UNTIL does not have the value type of DTSTART';
            }
        }

        if ($case->dtStart->kind === DtStartKind::Date && (in_array($frequency, self::SUB_DAILY, true) || isset($parts['BYHOUR']) || isset($parts['BYMINUTE']) || isset($parts['BYSECOND']))) {
            $violations[] = 'a time part or sub-daily FREQ with a DATE start';
        }

        if (isset($parts['BYWEEKNO']) && $frequency !== 'YEARLY') {
            $violations[] = 'BYWEEKNO without FREQ=YEARLY';
        }

        if (isset($parts['BYYEARDAY']) && in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
            $violations[] = 'BYYEARDAY with FREQ=' . $frequency;
        }

        if (isset($parts['BYMONTHDAY']) && $frequency === 'WEEKLY') {
            $violations[] = 'BYMONTHDAY with FREQ=WEEKLY';
        }

        if (isset($parts['BYDAY']) && preg_match('~\d~', $parts['BYDAY']) === 1 && (!in_array($frequency, ['MONTHLY', 'YEARLY'], true) || isset($parts['BYWEEKNO']))) {
            $violations[] = 'a BYDAY ordinal with FREQ=' . $frequency . (isset($parts['BYWEEKNO']) ? ' and BYWEEKNO' : '');
        }

        if (isset($parts['BYSETPOS']) && $byParts === []) {
            $violations[] = 'BYSETPOS without another BYxxx part';
        }

        if (isset($parts['SKIP']) && !isset($parts['RSCALE'])) {
            $violations[] = 'SKIP without RSCALE';
        }

        return $violations;
    }

    /**
     * @return array<string, string>
     */
    private static function parseParts(string $rrule): array
    {
        preg_match_all('~(?:^|;)([A-Z]+)=([^;]*)~', $rrule, $matches);

        return array_combine($matches[1], $matches[2]);
    }

    /**
     * @return list<GeneratedRecurrence>
     */
    private static function generate(
        int $seed,
        int $count,
    ): array
    {
        $generator = new RandomRuleGenerator($seed);
        $cases = [];

        for ($i = 0; $i < $count; $i++) {
            $cases[] = $generator->next();
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private static function generateStrings(
        int $seed,
        int $count,
    ): array
    {
        return array_map(static fn (GeneratedRecurrence $case): string => $case->toString(), self::generate($seed, $count));
    }

}
