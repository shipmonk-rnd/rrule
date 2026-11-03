<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Reference;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
use function count;
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

        $actual = [];

        foreach (Recurrence::of($start, $rule) as $occurrence) {
            $value = $occurrence->toString();

            if ($case->completeBefore !== null && $value >= $case->completeBefore) {
                break;
            }

            $actual[] = $value;

            if (count($actual) >= count($case->expected) && $case->completeBefore !== null && str_ends_with($case->completeBefore, '!')) {
                break;
            }
        }

        self::assertSame($case->expected, $actual, $case->description . ' complete before ' . ($case->completeBefore ?? '-'));
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
