<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Corpus;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipMonk\RRule\DtStartPolicy;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Internal\DateMatcher;
use ShipMonk\RRule\Internal\ExpansionPlan;
use ShipMonk\RRule\Internal\LocalRuleIterator;
use ShipMonk\RRule\Internal\RecurrenceEngine;
use ShipMonk\RRule\Internal\RuleStream;
use ShipMonk\RRule\Internal\SearchBudget;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\Internal\Values\DateValues;
use ShipMonk\RRule\Internal\Values\InstantValues;
use ShipMonk\RRule\Internal\Values\LocalValues;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\RecurrenceParser;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRuleTests\Reference\NaiveExpander;
use function array_filter;
use function array_values;
use function count;
use function file_get_contents;
use function glob;
use function is_array;
use function json_decode;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * Runs the imported third-party corpora (see tests/Corpus/README.md). A case that disagrees with the library must
 * be listed in {@see CorpusDeviations} with a reason; it is never skipped silently.
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
#[CoversClass(LocalValues::class)]
#[CoversClass(InstantValues::class)]
#[CoversClass(ParseException::class)]
final class CorpusTest extends TestCase
{

    #[DataProvider('provideExpansionCases')]
    public function testExpansion(CorpusCase $case): void
    {
        if ($case->skip !== null) {
            self::markTestSkipped(sprintf('%s: %s', $case->id, $case->skip));
        }

        [$reason, $mode] = CorpusDeviations::get()[$case->id] ?? ['', null];
        $text = $case->getRecurrence();

        try {
            $recurrence = (new RecurrenceParser(ParseMode::Strict))->parseRecurrence($text)->value;
            $lenient = false;
        } catch (ParseException $e) {
            if ($mode === 'reject' || ($case->expectedError !== null && $mode === null)) {
                self::assertNotSame('', $e->getMessage());

                return;
            }

            $recurrence = (new RecurrenceParser(ParseMode::Lenient))->parseRecurrence($text)->value;
            $lenient = true;
        }

        self::assertNotSame('reject', $mode, "$case->id: expected a strict parse error ($reason)");
        self::assertTrue($case->expectedError === null || $mode !== null, "$case->id: the source rejects this input, the library accepts it" . ($lenient ? ' (lenient)' : ''));

        $recurrence = $recurrence->withDtStartPolicy($case->dtStartPolicy);
        $expected = is_array($mode) ? $mode : ($mode === 'naive' ? self::expandNaively($recurrence) : $case->expected);
        $truncated = $case->truncated || !$recurrence->isFinite() || $mode === 'naive';
        $startAt = $case->startAt;
        $withOffset = $expected !== [] && strlen($expected[0]) > 16;

        if ($startAt !== null) {
            $expected = array_values(array_filter($expected, static fn (string $value): bool => substr($value, 0, strlen($startAt)) >= $startAt));
        }

        $actual = [];
        $limit = count($expected) + ($truncated ? 0 : 1);

        foreach ($recurrence as $occurrence) {
            if (count($actual) >= $limit) {
                break;
            }

            $value = self::format($occurrence, $withOffset);

            if ($startAt !== null && substr($value, 0, strlen($startAt)) < $startAt) {
                continue;
            }

            $actual[] = $value;
        }

        self::assertSame($expected, $actual, sprintf('%s%s%s', $case->id, $lenient ? ' (lenient parse)' : '', $reason !== '' ? " [deviation: $reason]" : ''));
    }

    /**
     * @return iterable<string, array{CorpusCase}>
     */
    public static function provideExpansionCases(): iterable
    {
        foreach (self::loadCases('cases.json') as $case) {
            yield $case->id => [$case];
        }
    }

    #[DataProvider('provideInvalidCases')]
    public function testInvalidInput(CorpusCase $case): void
    {
        [$reason, $mode] = CorpusDeviations::get()[$case->id] ?? ['', null];
        $parser = new RecurrenceParser(ParseMode::Strict);

        try {
            if ($case->rule !== null) {
                $parser->parseRule($case->rule);
            } else {
                $parser->parseRecurrence($case->getRecurrence());
            }
        } catch (ParseException $e) {
            self::assertNotSame('accept', $mode, "$case->id: the library rejects input the RFC allows ($reason): " . $e->getMessage());

            return;
        }

        self::assertSame('accept', $mode, "$case->id: the library accepts input that the source rejects with: " . ($case->expectedError ?? '?'));
    }

    /**
     * @return iterable<string, array{CorpusCase}>
     */
    public static function provideInvalidCases(): iterable
    {
        foreach (self::loadCases('invalid.json') as $case) {
            yield $case->id => [$case];
        }
    }

    public function testEveryDeviationBelongsToACase(): void
    {
        $ids = [];

        foreach ([...self::loadCases('cases.json'), ...self::loadCases('invalid.json')] as $case) {
            $ids[$case->id] = true;
        }

        foreach (CorpusDeviations::get() as $id => $deviation) {
            self::assertArrayHasKey($id, $ids, "Deviation $id has no corpus case");
        }
    }

    /**
     * @param Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable> $recurrence
     * @return list<string>
     */
    private static function expandNaively(Recurrence $recurrence): array
    {
        $start = $recurrence->getStart();
        self::assertInstanceOf(LocalDateTime::class, $start, 'Naive comparison needs a floating start');
        $rules = $recurrence->getRules();
        self::assertCount(1, $rules);
        [$expanded] = NaiveExpander::expand($rules[0], $start, $recurrence->getDtStartPolicy() === DtStartPolicy::Always, 30, $start->year + 50, 20_000);
        $result = [];

        foreach ($expanded as $value) {
            $result[] = str_replace(['-', ':'], '', $value);
        }

        return $result;
    }

    private static function format(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        bool $withOffset,
    ): string
    {
        if ($value instanceof LocalDate) {
            return sprintf('%04d%02d%02d', $value->year, $value->month, $value->day);
        }

        if ($value instanceof LocalDateTime) {
            return sprintf('%04d%02d%02dT%02d%02d%02d', $value->year, $value->month, $value->day, $value->hour, $value->minute, $value->second);
        }

        if ($value->getTimezone()->getName() === 'UTC') {
            return $value->format('Ymd\THis\Z');
        }

        return $value->format($withOffset ? 'Ymd\THisO' : 'Ymd\THis');
    }

    /**
     * @return list<CorpusCase>
     */
    private static function loadCases(string $fileName): array
    {
        $paths = glob(__DIR__ . '/data/*/' . $fileName);
        $cases = [];

        foreach ($paths === false ? [] : $paths as $path) {
            $file = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($file) || !is_array($file['cases'] ?? null)) {
                throw new LogicException("Invalid corpus file $path");
            }

            $policy = match ($file['dtStartPolicy'] ?? null) {
                'Always' => DtStartPolicy::Always,
                'OnlyIfMatching' => DtStartPolicy::OnlyIfMatching,
                default => null,
            };

            foreach ($file['cases'] as $case) {
                $cases[] = CorpusCase::fromArray(is_array($case) ? $case : throw new LogicException("Invalid case in $path"), $policy);
            }
        }

        return $cases;
    }

}
