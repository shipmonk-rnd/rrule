<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal\Values;

use DateTimeImmutable;
use LogicException;
use ShipMonk\RRule\Exception\InvalidArgumentException;
use ShipMonk\RRule\Internal\Civil;
use ShipMonk\RRule\Internal\ValueKind;
use ShipMonk\RRule\LocalDate;
use ShipMonk\RRule\LocalDateTime;
use function sprintf;

/**
 * @implements ValueConverter<LocalDate>
 *
 * @internal
 */
final class DateValues implements ValueConverter
{

    public function getKind(): ValueKind
    {
        return ValueKind::Date;
    }

    public function accept(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): LocalDate
    {
        if (!$value instanceof LocalDate) {
            throw new InvalidArgumentException(sprintf('The %s value must be a LocalDate, because the start is a date', $name));
        }

        return $value;
    }

    public function acceptStored(
        LocalDate|LocalDateTime|DateTimeImmutable $value,
        string $name,
    ): LocalDate
    {
        return $this->accept($value, $name);
    }

    public function toPosition(LocalDate|LocalDateTime|DateTimeImmutable $value): int
    {
        if (!$value instanceof LocalDate) {
            throw new LogicException('Expected LocalDate');
        }

        return $value->toDayNumber() * Civil::SECONDS_PER_DAY;
    }

    public function fromPosition(int $position): LocalDate
    {
        return LocalDate::fromDayNumber(Civil::floorDiv($position, Civil::SECONDS_PER_DAY));
    }

}
