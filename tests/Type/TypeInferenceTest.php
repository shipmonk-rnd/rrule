<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Checks the types that PHPStan infers for code that uses the public API, so that the generics and the precise
 * integer ranges keep working for users.
 */
#[CoversNothing]
final class TypeInferenceTest extends TypeInferenceTestCase
{

    /**
     * @return iterable<mixed>
     */
    public static function provideAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/recurrence.php');
    }

    #[DataProvider('provideAsserts')]
    public function testType(
        string $assertType,
        string $file,
        mixed ...$args,
    ): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan.neon'];
    }

}
