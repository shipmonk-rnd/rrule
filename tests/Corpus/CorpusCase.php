<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Corpus;

use LogicException;
use ShipMonk\RRule\DtStartPolicy;
use function array_is_list;
use function is_array;
use function is_bool;
use function is_string;

/**
 * One case of a tests/Corpus/data file, validated when it is read.
 */
final class CorpusCase
{

    /**
     * @param list<string> $expected
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $recurrence,
        public readonly ?string $rule,
        public readonly ?string $startAt,
        public readonly array $expected,
        public readonly ?string $expectedError,
        public readonly bool $truncated,
        public readonly ?string $skip,
        public readonly DtStartPolicy $dtStartPolicy,
    )
    {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(
        array $data,
        ?DtStartPolicy $filePolicy,
    ): self
    {
        $notes = $data['notes'] ?? null;
        $policy = self::optionalString($data, 'dtStartPolicy');
        $expected = $data['expected'] ?? [];

        if (!is_array($expected) || !array_is_list($expected)) {
            throw new LogicException('Corpus field "expected" must be a list');
        }

        $values = [];

        foreach ($expected as $value) {
            $values[] = is_string($value) ? $value : throw new LogicException('Corpus field "expected" must contain strings');
        }

        $truncated = $data['truncated'] ?? false;

        return new self(
            id: self::optionalString($data, 'id') ?? throw new LogicException('Corpus case without id'),
            recurrence: self::optionalString($data, 'recurrence'),
            rule: self::optionalString($data, 'rule'),
            startAt: self::optionalString($data, 'startAt'),
            expected: $values,
            expectedError: self::optionalString($data, 'expectedError'),
            truncated: is_bool($truncated) ? $truncated : throw new LogicException('Corpus field "truncated" must be a boolean'),
            skip: is_array($notes) ? self::optionalString($notes, 'skip') : null,
            dtStartPolicy: match ($policy) {
                'Always' => DtStartPolicy::Always,
                'OnlyIfMatching' => DtStartPolicy::OnlyIfMatching,
                null => $filePolicy ?? DtStartPolicy::Always,
                default => throw new LogicException("Unknown dtStartPolicy $policy"),
            },
        );
    }

    public function getRecurrence(): string
    {
        return $this->recurrence ?? throw new LogicException("Corpus case $this->id has no recurrence");
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalString(
        array $data,
        string $key,
    ): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null || is_string($value) ? $value : throw new LogicException("Corpus field \"$key\" must be a string");
    }

}
