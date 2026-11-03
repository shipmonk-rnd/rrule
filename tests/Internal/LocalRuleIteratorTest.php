<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Internal\DateMatcher;
use ShipMonk\RRule\Internal\ExpansionPlan;
use ShipMonk\RRule\Internal\LocalRuleIterator;
use ShipMonk\RRule\Internal\SearchBudget;
use ShipMonk\RRule\RecurrenceRule;
use function iterator_to_array;

#[CoversClass(LocalRuleIterator::class)]
final class LocalRuleIteratorTest extends TestCase
{

    public function testCandidatesStopAtTheUpperBoundInsideAPeriod(): void
    {
        $start = FloatingDateTime::of(2026, 1, 1)->toLocalSeconds();

        self::assertSame([$start], self::generate(new RecurrenceRule(Frequency::Hourly, byMinute: [0, 30]), $start, $start + 15 * 60));
        self::assertSame([$start], self::generate(new RecurrenceRule(Frequency::Daily, byHour: [0, 12]), $start, $start + 6 * 3600));
    }

    /**
     * @return list<int>
     */
    private static function generate(
        RecurrenceRule $rule,
        int $startLocal,
        int $toLocal,
    ): array
    {
        $plan = ExpansionPlan::compile($rule, $startLocal);

        return iterator_to_array((new LocalRuleIterator($plan, new DateMatcher($plan), new SearchBudget(10)))->generate(null, $toLocal), false);
    }

}
