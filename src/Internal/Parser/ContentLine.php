<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Parser;

use ShipMonk\RRule\Exception\ParseException;
use function count;
use function sprintf;

/**
 * One unfolded content line (RFC 5545 section 3.1).
 *
 * @internal
 */
final class ContentLine
{

    /**
     * @param string                      $name       upper-case property name
     * @param array<string, list<string>> $parameters upper-case parameter name => all its values, unquoted
     * @param positive-int                $lineNumber
     */
    public function __construct(
        public readonly string $name,
        private readonly array $parameters,
        public readonly string $value,
        public readonly int $lineNumber,
    )
    {
    }

    /**
     * @throws ParseException
     */
    public function getParameter(string $name): ?string
    {
        $values = $this->parameters[$name] ?? [];

        if (count($values) > 1) {
            throw ParseErrors::create(sprintf('The %s parameter of %s must have exactly one value', $name, $this->name), $this->lineNumber);
        }

        return $values[0] ?? null;
    }

}
