<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Parser;

use DateTimeImmutable;
use ShipMonk\RRule\Exception\ParseException;
use ShipMonk\RRule\Internal\Parser\ContentLineReader;
use ShipMonk\RRule\Internal\Parser\ParseContext;
use ShipMonk\RRule\Internal\Parser\ParseErrors;
use ShipMonk\RRule\Internal\Parser\RecurrenceTextParser;
use ShipMonk\RRule\Internal\Parser\RuleParser;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use function count;
use function sprintf;
use function strlen;
use function strncasecmp;
use function substr;
use function trim;

/**
 * Parses RFC 5545 recurrence text. Names and enumerated values are case-insensitive in both modes
 * (RFC 5545 section 3.1). {@see ParseMode::Lenient} accepts a closed list of real-world deviations
 * and reports each one as a {@see ParseWarning}.
 *
 * @api
 */
final class RecurrenceParser
{

    private const RULE_PREFIX = 'RRULE:';

    public function __construct(
        private readonly ParseMode $mode = ParseMode::Strict,
    )
    {
    }

    /**
     * Parses an RFC 5545 RECUR value ("FREQ=...;..."). A leading "RRULE:" is accepted.
     *
     * @return ParseResult<RecurrenceRule>
     *
     * @throws ParseException
     */
    public function parseRule(string $value): ParseResult
    {
        $context = new ParseContext($this->mode);
        $lines = ContentLineReader::unfold(trim($value));

        if (count($lines) > 1) {
            throw ParseErrors::create('The rule must be one line; use parseRecurrence() for DTSTART, RDATE and EXDATE lines', null);
        }

        $text = $lines[0];

        if (strncasecmp($text, self::RULE_PREFIX, strlen(self::RULE_PREFIX)) === 0) {
            $text = substr($text, strlen(self::RULE_PREFIX));
        }

        $ruleParser = new RuleParser($context);
        $rawRule = $ruleParser->split($text, null);

        if ($rawRule->start !== null) {
            $context->warn(
                ParseWarningCode::StartInsideRule,
                sprintf('Ignored DTSTART=%s inside the rule, because a rule has no start (parseRecurrence() uses it)', $rawRule->start),
                null,
            );
        }

        return new ParseResult($ruleParser->build($rawRule, null), $context->getWarnings());
    }

    /**
     * Parses DTSTART, RRULE, RDATE, EXDATE (and in lenient mode EXRULE) content lines.
     * Lines may end with CRLF or LF and may be folded.
     *
     * @return ParseResult<Recurrence<LocalDate>|Recurrence<LocalDateTime>|Recurrence<DateTimeImmutable>>
     *
     * @throws ParseException
     */
    public function parseRecurrence(string $text): ParseResult
    {
        $context = new ParseContext($this->mode);
        $recurrence = (new RecurrenceTextParser($context))->parse($text);

        return new ParseResult($recurrence, $context->getWarnings());
    }

}
