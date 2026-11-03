<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential\Python;

use DateTimeZone;
use LogicException;
use function count;
use function implode;
use function pack;
use function sprintf;
use function str_repeat;
use function strlen;

/**
 * Writes the transitions that PHP knows for a zone as a TZif version 2 file (RFC 8536).
 * Python's zoneinfo can load it, so that both sides resolve local times with exactly the same zone data.
 */
final class TzifWriter
{

    /**
     * The file has explicit transitions only (no POSIX TZ footer): after the last transition the last offset stays.
     * The state at $begin is written as the first transition, so the "before the first transition" rule of the
     * reader never applies to times from $begin on.
     */
    public static function fromTimeZone(
        DateTimeZone $zone,
        int $begin,
        int $end,
    ): string
    {
        $transitions = $zone->getTransitions($begin, $end);

        if ($transitions === []) {
            throw new LogicException(sprintf('No transitions for %s', $zone->getName()));
        }

        $times = [];
        $typeIndexes = [];
        $typeKeys = [];
        $types = [];
        $designations = '';
        $designationIndexes = [];

        foreach ($transitions as $index => $transition) {
            $typeKey = sprintf('%d/%d/%s', $transition['offset'], $transition['isdst'] ? 1 : 0, $transition['abbr']);

            if (!isset($typeKeys[$typeKey])) {
                if (!isset($designationIndexes[$transition['abbr']])) {
                    $designationIndexes[$transition['abbr']] = strlen($designations);
                    $designations .= $transition['abbr'] . "\0";
                }

                $typeKeys[$typeKey] = count($types);
                $types[] = pack('NCC', $transition['offset'] & 0xFFFFFFFF, $transition['isdst'] ? 1 : 0, $designationIndexes[$transition['abbr']]);
            }

            $times[] = self::packInt64($index === 0 ? $begin : $transition['ts']);
            $typeIndexes[] = pack('C', $typeKeys[$typeKey]);
        }

        $version1 = self::header(0, 1, 1) . pack('NCC', 0, 0, 0) . "\0";
        $version2 = self::header(count($times), count($types), strlen($designations))
            . implode('', $times)
            . implode('', $typeIndexes)
            . implode('', $types)
            . $designations;

        return $version1 . $version2 . "\n\n";
    }

    private static function header(
        int $transitionCount,
        int $typeCount,
        int $designationLength,
    ): string
    {
        return 'TZif2' . str_repeat("\0", 15) . pack('NNNNNN', 0, 0, 0, $transitionCount, $typeCount, $designationLength);
    }

    private static function packInt64(int $value): string
    {
        return pack('J', $value);
    }

}
