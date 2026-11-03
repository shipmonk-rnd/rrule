<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Parser;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\ParseWarning;
use ShipMonk\RRule\Parser\ParseWarningCode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use ShipMonk\RRule\Recurrence;
use Throwable;
use function array_map;
use function get_class;
use function sprintf;

abstract class ParserTestCase extends TestCase
{

    /**
     * @param class-string<Throwable>|null $previous
     */
    protected static function assertRuleError(
        string $input,
        string $message,
        ParseMode $mode = ParseMode::Strict,
        ?string $previous = null,
    ): void
    {
        try {
            (new RecurrenceParser($mode))->parseRule($input);
        } catch (ParseException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertNull($e->lineNumber);
            self::assertSame($previous, $e->getPrevious() === null ? null : get_class($e->getPrevious()));

            return;
        }

        self::fail(sprintf('Expected ParseException "%s"', $message));
    }

    /**
     * @param class-string<Throwable>|null $previous
     */
    protected static function assertRecurrenceError(
        string $input,
        string $message,
        ?int $lineNumber,
        ParseMode $mode = ParseMode::Strict,
        ?string $previous = null,
    ): void
    {
        try {
            (new RecurrenceParser($mode))->parseRecurrence($input);
        } catch (ParseException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($lineNumber, $e->lineNumber);
            self::assertSame($previous, $e->getPrevious() === null ? null : get_class($e->getPrevious()));

            return;
        }

        self::fail(sprintf('Expected ParseException "%s"', $message));
    }

    /**
     * @param list<array{ParseWarningCode, string, int|null}> $expected
     * @param list<ParseWarning>                              $actual
     */
    protected static function assertWarnings(
        array $expected,
        array $actual,
    ): void
    {
        self::assertSame(
            $expected,
            array_map(static fn (ParseWarning $warning): array => [$warning->code, $warning->message, $warning->lineNumber], $actual),
        );
    }

    /**
     * @param Recurrence<CalendarDate>|Recurrence<FloatingDateTime>|Recurrence<DateTimeImmutable> $recurrence
     */
    protected static function serialize(Recurrence $recurrence): string
    {
        return $recurrence->toString("\n");
    }

    protected static function describe(CalendarDate|FloatingDateTime|DateTimeImmutable $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d\TH:i:sP e');
        }

        return $value->toString() . ($value instanceof CalendarDate ? ' date' : ' floating');
    }

}
