<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Parser\ParseMode;
use ShipMonk\RRule\Parser\ParseWarning;
use ShipMonk\RRule\Parser\ParseWarningCode;
use function usort;
use const PHP_INT_MAX;

/**
 * The parse mode and the warnings of one parse call.
 *
 * @internal
 */
final class ParseContext
{

    /**
     * @var list<ParseWarning>
     */
    private array $warnings = [];

    public function __construct(
        private readonly ParseMode $mode,
    )
    {
    }

    public function isLenient(): bool
    {
        return $this->mode === ParseMode::Lenient;
    }

    /**
     * @param positive-int|null $lineNumber
     */
    public function warn(
        ParseWarningCode $code,
        string $message,
        ?int $lineNumber,
    ): void
    {
        $this->warnings[] = new ParseWarning($code, $message, $lineNumber);
    }

    /**
     * A deviation from the RFC: an error in strict mode, a warning in lenient mode.
     *
     * @param positive-int|null $lineNumber
     *
     * @throws ParseException
     */
    public function deviate(
        ParseWarningCode $code,
        string $error,
        string $warning,
        ?int $lineNumber,
    ): void
    {
        if (!$this->isLenient()) {
            throw ParseErrors::create($error, $lineNumber);
        }

        $this->warn($code, $warning, $lineNumber);
    }

    /**
     * The parser reads the input in two passes, so the warnings are sorted back into the order of the lines.
     *
     * @return list<ParseWarning>
     */
    public function getWarnings(): array
    {
        $warnings = $this->warnings;
        usort($warnings, static fn (ParseWarning $a, ParseWarning $b): int => ($a->lineNumber ?? PHP_INT_MAX) <=> ($b->lineNumber ?? PHP_INT_MAX));

        return $warnings;
    }

}
