<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential\Python;

use LogicException;
use ShipMonk\RRuleTests\Differential\AdapterUnavailableException;
use function array_is_list;
use function array_map;
use function base64_encode;
use function count;
use function fwrite;
use function getenv;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function rewind;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function tmpfile;
use const JSON_THROW_ON_ERROR;

/**
 * Runs the Python scripts in this directory: zoneinfo (local time to instant) and python-dateutil (expansion).
 * The scripts run in isolated mode (python3 -I), so PYTHON* environment variables have no effect.
 */
final class PythonAdapter
{

    private const EXIT_COMMAND_NOT_FOUND = 127;

    public function __construct(
        public readonly string $python,
    )
    {
    }

    /**
     * Uses the Python binary from the environment variable DIFFERENTIAL_PYTHON, or "python3".
     */
    public static function fromEnvironment(): self
    {
        $python = getenv('DIFFERENTIAL_PYTHON');

        return new self(is_string($python) && $python !== '' ? $python : 'python3');
    }

    /**
     * Resolves local wall times to Unix times with zoneinfo and fold=0 (RFC 5545 section 3.3.5 semantics).
     *
     * @param list<array{zone: string, local: string}> $points     local time in the form 2026-10-25T02:30:00
     * @param array<string, string>                    $tzifByZone binary TZif data that replaces the zone data from the Python TZ path
     * @return list<int>
     *
     * @throws AdapterUnavailableException
     */
    public function resolveWithZoneinfo(
        array $points,
        array $tzifByZone = [],
    ): array
    {
        $request = $tzifByZone === []
            ? $points
            : ['tzif' => array_map(base64_encode(...), $tzifByZone), 'points' => $points];

        $result = $this->run(__DIR__ . '/zoneinfo_resolve.py', $request);

        if (!is_array($result) || !array_is_list($result) || count($result) !== count($points)) {
            throw new LogicException('zoneinfo_resolve.py returned an unexpected result');
        }

        return array_map(static function (mixed $unix): int {
            if (!is_int($unix)) {
                throw new LogicException('zoneinfo_resolve.py returned a non-integer time');
            }

            return $unix;
        }, $result);
    }

    /**
     * Expands recurrences with python-dateutil (rrulestr with forceset=True, TZID values as zoneinfo zones).
     * Each result is the list of the first "limit" occurrences, or an error message.
     *
     * @param list<array{recurrence: string, limit: int}> $cases recurrence: content lines, for example "DTSTART:20260101T090000\nRRULE:FREQ=DAILY"
     * @return list<list<string>|string>
     *
     * @throws AdapterUnavailableException
     */
    public function expandWithDateutil(array $cases): array
    {
        $result = $this->run(__DIR__ . '/dateutil_expand.py', $cases);

        if (!is_array($result) || !array_is_list($result) || count($result) !== count($cases)) {
            throw new LogicException('dateutil_expand.py returned an unexpected result');
        }

        return array_map(static function (mixed $caseResult): array|string {
            if (is_string($caseResult)) {
                return $caseResult;
            }

            if (!is_array($caseResult) || !array_is_list($caseResult)) {
                throw new LogicException('dateutil_expand.py returned an unexpected case result');
            }

            return array_map(static function (mixed $occurrence): string {
                if (!is_string($occurrence)) {
                    throw new LogicException('dateutil_expand.py returned a non-string occurrence');
                }

                return $occurrence;
            }, $caseResult);
        }, $result);
    }

    /**
     * @throws AdapterUnavailableException
     */
    private function run(
        string $script,
        mixed $request,
    ): mixed
    {
        // Temporary files instead of pipes: no deadlock when both sides write a lot.
        $stdin = tmpfile();
        $stdout = tmpfile();
        $stderr = tmpfile();

        if ($stdin === false || $stdout === false || $stderr === false) {
            throw new LogicException('Cannot create temporary files');
        }

        fwrite($stdin, json_encode($request, JSON_THROW_ON_ERROR));
        rewind($stdin);

        $process = @proc_open([$this->python, '-I', $script], [0 => $stdin, 1 => $stdout, 2 => $stderr], $pipes);

        if (!is_resource($process)) {
            throw new AdapterUnavailableException(sprintf('Cannot run %s', $this->python));
        }

        $exitCode = proc_close($process);
        rewind($stdout);
        rewind($stderr);
        $output = (string) stream_get_contents($stdout);
        $errorOutput = (string) stream_get_contents($stderr);

        if ($exitCode === self::EXIT_COMMAND_NOT_FOUND || str_contains($errorOutput, 'ModuleNotFoundError')) {
            throw new AdapterUnavailableException(sprintf('%s cannot run %s (set DIFFERENTIAL_PYTHON): %s', $this->python, $script, $errorOutput));
        }

        if ($exitCode !== 0) {
            throw new LogicException(sprintf('%s failed with exit code %d: %s', $script, $exitCode, $errorOutput));
        }

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

}
