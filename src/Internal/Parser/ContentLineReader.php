<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

use ShipMonk\RRule\Exception\ParseException;
use function array_key_last;
use function explode;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function strcspn;
use function strlen;
use function strpos;
use function substr;

/**
 * Splits text into content lines (RFC 5545 section 3.1).
 *
 * @internal
 */
final class ContentLineReader
{

    /**
     * Accepts CRLF and LF line breaks and removes folding (a line break followed by one space or tab).
     * Trailing whitespace of the text is removed. The result keeps empty lines, so that its index gives the line number.
     *
     * @return non-empty-list<string>
     */
    public static function unfold(string $text): array
    {
        $lines = [];

        foreach (explode("\n", rtrim($text)) as $physicalLine) {
            if (str_ends_with($physicalLine, "\r")) {
                $physicalLine = substr($physicalLine, 0, -1);
            }

            $last = array_key_last($lines);
            $first = $physicalLine[0] ?? '';

            if ($last !== null && ($first === ' ' || $first === "\t")) {
                $lines[$last] .= substr($physicalLine, 1);
                continue;
            }

            $lines[] = $physicalLine;
        }

        return $lines;
    }

    /**
     * @return list<ContentLine>
     *
     * @throws ParseException
     */
    public static function read(string $text): array
    {
        $contentLines = [];

        foreach (self::unfold($text) as $index => $line) {
            if ($line === '') {
                continue;
            }

            $contentLines[] = self::parseLine($line, $index + 1);
        }

        return $contentLines;
    }

    /**
     * @param positive-int $lineNumber
     *
     * @throws ParseException
     */
    private static function parseLine(
        string $line,
        int $lineNumber,
    ): ContentLine
    {
        if (preg_match('~[\x00-\x08\x0A-\x1F\x7F]~', $line) === 1) {
            throw ParseErrors::create('The line contains a control character', $lineNumber);
        }

        if (preg_match('~^[A-Za-z0-9-]+~', $line, $matches) !== 1) {
            throw ParseErrors::create(sprintf('"%s" does not start with a property name', $line), $lineNumber);
        }

        $name = $matches[0];
        $position = strlen($name);
        $parameters = [];

        while (($line[$position] ?? '') === ';') {
            if (preg_match('~\G;([A-Za-z0-9-]+)=~', $line, $matches, 0, $position) !== 1) {
                throw ParseErrors::create(sprintf('Invalid parameter in "%s"', $line), $lineNumber);
            }

            $parameterName = ValueParser::upper($matches[1]);
            $position += strlen($matches[0]);

            while (true) {
                if (($line[$position] ?? '') === '"') {
                    $end = strpos($line, '"', $position + 1);

                    if ($end === false) {
                        throw ParseErrors::create(sprintf('A quoted parameter value is not closed in "%s"', $line), $lineNumber);
                    }

                    $parameters[$parameterName][] = substr($line, $position + 1, $end - $position - 1);
                    $position = $end + 1;
                } else {
                    $length = strcspn($line, '";:,', $position);
                    $parameters[$parameterName][] = substr($line, $position, $length);
                    $position += $length;
                }

                if (($line[$position] ?? '') !== ',') {
                    break;
                }

                $position++;
            }
        }

        if (($line[$position] ?? '') !== ':') {
            throw ParseErrors::create(sprintf('":" is missing after the property name and parameters in "%s"', $line), $lineNumber);
        }

        return new ContentLine(ValueParser::upper($name), $parameters, substr($line, $position + 1), $lineNumber);
    }

}
