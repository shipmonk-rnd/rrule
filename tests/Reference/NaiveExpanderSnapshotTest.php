<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Reference;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function array_keys;
use function range;

/**
 * Checks that the snapshot holds the current {@see NaiveExpander} result for every seed. This test takes minutes,
 * so it is in the reference test suite (composer check:reference), not in the default one.
 */
#[CoversNothing]
final class NaiveExpanderSnapshotTest extends TestCase
{

    /**
     * @var array<int, ReferenceCase>|null
     */
    private static ?array $snapshot = null;

    public function testSnapshotHasEverySeed(): void
    {
        self::assertSame(range(1, ReferenceCase::SEEDS), array_keys(self::getSnapshot()));
    }

    #[DataProvider('provideSeeds')]
    public function testSnapshotMatchesNaiveExpansion(int $seed): void
    {
        self::assertSame(
            ReferenceCase::expandNaively($seed)->toArray(),
            (self::getSnapshot()[$seed] ?? null)?->toArray(),
            'The snapshot is out of date: run composer update:reference-snapshot',
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideSeeds(): iterable
    {
        for ($seed = 1; $seed <= ReferenceCase::SEEDS; $seed++) {
            yield "seed $seed" => [$seed];
        }
    }

    /**
     * @return array<int, ReferenceCase>
     */
    private static function getSnapshot(): array
    {
        return self::$snapshot ??= ReferenceCase::loadSnapshot();
    }

}
