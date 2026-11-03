<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use DateTimeImmutable;
use DateTimeZone;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Internal\TimeZoneResolver;
use ShipMonk\RRule\Until;
use ShipMonk\RRuleTests\Oracle\Generator\GeneratedRecurrence;
use ShipMonk\RRuleTests\Reference\NaiveExpander;
use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function gmdate;
use function ksort;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Decides a disagreement between the engine and an oracle with the slow JSCalendar reference implementation.
 */
final class ReferenceArbiter
{

    /**
     * The slow JSCalendar reference implementation decides: when it agrees with the engine and not with libical,
     * the difference belongs to the oracle. For a zoned start, the reference expands the local times and the library
     * resolver (checked against Python zoneinfo for every zone) converts them to instants.
     *
     * @param list<string> $ours
     * @param list<string> $theirs
     */
    public static function agreesWithEngine(
        GeneratedRecurrence $case,
        array $ours,
        array $theirs,
        int $limit,
    ): bool
    {
        $value = $case->dtStart->value;
        $start = FloatingDateTime::of((int) substr($value, 0, 4), (int) substr($value, 4, 2), (int) substr($value, 6, 2), (int) substr($value, 9, 2), (int) substr($value, 11, 2), (int) substr($value, 13, 2));
        $zoned = $case->dtStart->kind === DtStartKind::Zoned;
        $resolver = new TimeZoneResolver(new DateTimeZone($zoned ? (string) $case->dtStart->timeZone : 'UTC'));
        $rule = $case->rule;
        $until = $rule->end instanceof Until ? $rule->end->value : null;

        if ($until instanceof DateTimeImmutable) {
            $rule = $rule->withEnd(Until::floating(FloatingDateTime::fromLocalSeconds($resolver->unixToLocal($until->getTimestamp()) + 86_400)));
        } elseif ($until instanceof CalendarDate) {
            $rule = $rule->withEnd(Until::floating($until->atTime(23, 59, 59)));
        }

        [$reference, $completeBefore] = NaiveExpander::expand($rule, $start, false, $limit * 3, 9999, 300_000);
        $startUnix = $resolver->localToUnix($start->toLocalSeconds());
        $untilUnix = match (true) {
            $until instanceof DateTimeImmutable => $until->getTimestamp(),
            $until instanceof FloatingDateTime => $resolver->localToUnix($until->toLocalSeconds()),
            $until instanceof CalendarDate => $resolver->localToUnix($until->toDayNumber() * 86_400 + 86_399),
            default => null,
        };
        $boundary = $completeBefore === null || str_ends_with($completeBefore, '!') || str_starts_with($completeBefore, '10000')
            ? null
            : self::format($resolver->localToUnix(FloatingDateTime::fromDateTime(new DateTimeImmutable($completeBefore))->toLocalSeconds()), $case->dtStart->kind);
        $instants = [];

        foreach ($reference as $local) {
            $unix = $resolver->localToUnix(FloatingDateTime::fromDateTime(new DateTimeImmutable($local))->toLocalSeconds());

            if ($unix >= $startUnix && ($untilUnix === null || $unix <= $untilUnix)) {
                $instants[$unix] = true;
            }
        }

        ksort($instants);
        $formatted = [];

        foreach (array_keys($instants) as $unix) {
            $formatted[] = self::format($unix, $case->dtStart->kind);
        }

        $cut = static fn (array $list): array => array_values(array_filter(
            array_slice($list, 0, $limit),
            static fn (string $occurrence): bool => $boundary === null || $occurrence < $boundary,
        ));
        $formatted = $cut($formatted);

        if ($formatted === $cut($ours) && $formatted !== $cut($theirs)) {
            return true;
        }

        return false;
    }

    private static function format(
        int $unix,
        DtStartKind $kind,
    ): string
    {
        return match ($kind) {
            DtStartKind::Date => gmdate('Ymd', $unix),
            DtStartKind::Floating => gmdate('Ymd\THis', $unix),
            DtStartKind::Utc, DtStartKind::Zoned => gmdate('Ymd\THis\Z', $unix),
        };
    }

}
