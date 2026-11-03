<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Reference;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\DateMatcher;
use ShipMonk\RRule\Internal\ExpansionPlan;
use ShipMonk\RRule\Internal\LocalRuleIterator;
use ShipMonk\RRule\Internal\RecurrenceEngine;
use ShipMonk\RRule\Internal\RuleStream;
use ShipMonk\RRule\Internal\SearchBudget;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\Internal\Values\DateValues;
use ShipMonk\RRule\Internal\Values\FloatingValues;
use ShipMonk\RRule\Internal\Values\InstantValues;
use ShipMonk\RRule\Recurrence;
use function array_map;
use function array_unique;
use function count;
use function intdiv;
use function iterator_to_array;
use function str_ends_with;

/**
 * Compares the engine with the {@see NaiveExpander} results in the snapshot, on seeded random rules: all
 * frequencies, all BYxxx parts, negative values, ordinals, BYSETPOS, WKST, INTERVAL, COUNT, UNTIL and RFC 7529 SKIP.
 */
#[CoversClass(Recurrence::class)]
#[CoversClass(RecurrenceEngine::class)]
#[CoversClass(RuleStream::class)]
#[CoversClass(LocalRuleIterator::class)]
#[CoversClass(DateMatcher::class)]
#[CoversClass(ExpansionPlan::class)]
#[CoversClass(SearchBudget::class)]
#[CoversClass(ValueKind::class)]
#[CoversClass(DateValues::class)]
#[CoversClass(FloatingValues::class)]
#[CoversClass(InstantValues::class)]
final class NaiveExpanderDifferentialTest extends TestCase
{

    #[DataProvider('provideCases')]
    public function testEngineMatchesNaiveExpansion(ReferenceCase $case): void
    {
        [$rule, $start] = ReferenceCase::generate($case->seed);
        self::assertSame($case->description, ReferenceCase::describe($rule, $start), 'The snapshot is out of date: run composer update:reference-snapshot');

        $recurrence = Recurrence::of($start, $rule);
        $occurrences = [];

        foreach ($recurrence as $occurrence) {
            $value = $occurrence->toString();

            if ($case->completeBefore !== null && $value >= $case->completeBefore) {
                break;
            }

            $occurrences[] = $occurrence;

            if (count($occurrences) >= count($case->expected) && $case->completeBefore !== null && str_ends_with($case->completeBefore, '!')) {
                break;
            }
        }

        $actual = array_map(static fn (FloatingDateTime $occurrence): string => $occurrence->toString(), $occurrences);
        self::assertSame($case->expected, $actual, $case->description . ' complete before ' . ($case->completeBefore ?? '-'));

        // the skip-ahead queries must agree with iteration
        foreach (array_unique([0, intdiv(count($occurrences), 2), count($occurrences) - 2]) as $index) {
            $occurrence = $occurrences[$index] ?? null;
            $next = $occurrences[$index + 1] ?? null;

            if ($occurrence === null || $next === null) {
                continue;
            }

            $message = $case->description . ' at ' . $occurrence->toString();
            self::assertTrue($recurrence->occursAt($occurrence), $message);
            self::assertSame($occurrence->toString(), $recurrence->firstAfter($occurrence, inclusive: true)?->toString(), $message);
            self::assertSame($next->toString(), $recurrence->firstAfter($occurrence)?->toString(), $message);
            self::assertSame($occurrence->toString(), $recurrence->lastBefore($next)?->toString(), $message);
            self::assertSame([$occurrence->toString()], array_map(static fn (FloatingDateTime $value): string => $value->toString(), iterator_to_array($recurrence->between($occurrence, $next), false)), $message);
        }
    }

    /**
     * @return iterable<string, array{ReferenceCase}>
     */
    public static function provideCases(): iterable
    {
        foreach (ReferenceCase::loadSnapshot() as $seed => $case) {
            yield "seed $seed" => [$case];
        }
    }

}
