<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use LogicException;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use function sprintf;

/**
 * @implements ValueConverter<LocalDateTime>
 *
 * @internal
 */
final class LocalValues implements ValueConverter
{

    public function getKind(): ValueKind
    {
        return ValueKind::Floating;
    }

    public function accept(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): LocalDateTime
    {
        if (!$value instanceof LocalDateTime) {
            throw new InvalidArgumentException(sprintf('The %s value must be a LocalDateTime, because the start is a local date-time', $name));
        }

        return $value;
    }

    public function acceptStored(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): LocalDateTime
    {
        return $this->accept($value, $name);
    }

    public function toPosition(LocalDate|LocalDateTime|DateTimeImmutable $value): int
    {
        if (!$value instanceof LocalDateTime) {
            throw new LogicException('Expected LocalDateTime');
        }

        return $value->toLocalSeconds();
    }

    public function fromPosition(int $position): LocalDateTime
    {
        return LocalDateTime::fromLocalSeconds($position);
    }

}
