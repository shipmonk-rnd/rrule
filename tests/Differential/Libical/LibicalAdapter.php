<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential\Libical;

use FFI;
use FFI\CData;
use FFI\Exception as FfiException;
use LogicException;
use ShipMonk\RRuleTests\Differential\AdapterUnavailableException;
use ShipMonk\RRuleTests\Differential\DtStart;
use ShipMonk\RRuleTests\Differential\DtStartKind;
use function count;
use function extension_loaded;
use function getenv;
use function gmdate;
use function is_file;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Expands recurrence rules with libical 4.x through PHP FFI. The struct layouts and signatures come from
 * the libical 4.0 headers (icaltime.h, icalrecur.h); libical 3.x has a different, incompatible API.
 *
 * Time zones are libical builtin zones, which libical reads from the system zoneinfo directory.
 */
final class LibicalAdapter
{

    public const DEFAULT_LIBRARY_PATH = '/opt/libical-noicu/lib/libical.so';
    public const ICU_LIBRARY_PATH = '/opt/libical-icu/lib/libical.so';

    private const ICAL_NO_ERROR = 0;

    private const C_DECLARATIONS = <<<'C'
        typedef struct _icaltimezone icaltimezone;
        typedef struct icalrecur_iterator_impl icalrecur_iterator;
        struct icalrecurrencetype;
        typedef struct _icalarray {
            size_t element_size;
            size_t increment_size;
            size_t num_elements;
            size_t space_allocated;
            void **chunks;
        } icalarray;
        struct icaltimetype {
            int year;
            int month;
            int day;
            int hour;
            int minute;
            int second;
            int is_date;
            int is_daylight;
            const icaltimezone *zone;
        };

        struct icalrecurrencetype *icalrecurrencetype_new_from_string(const char *str);
        void icalrecurrencetype_unref(struct icalrecurrencetype *recur);
        icalarray *icalrecurrencetype_rscale_supported_calendars(void);
        void icalarray_free(icalarray *array);

        icalrecur_iterator *icalrecur_iterator_new(struct icalrecurrencetype *rule, struct icaltimetype dtstart);
        struct icaltimetype icalrecur_iterator_next(icalrecur_iterator *impl);
        void icalrecur_iterator_free(icalrecur_iterator *impl);

        struct icaltimetype icaltime_from_string(const char *str);
        struct icaltimetype icaltime_set_timezone(struct icaltimetype *t, const icaltimezone *zone);
        bool icaltime_is_null_time(const struct icaltimetype t);
        int64_t icaltime_as_timet_with_zone(const struct icaltimetype tt, const icaltimezone *zone);
        icaltimezone *icaltimezone_get_builtin_timezone(const char *location);

        int *icalerror_icalerrno(void);
        const char *icalerror_strerror(int e);
        void icalerror_clear_errno(void);
        void icalerror_set_errors_are_fatal(bool fatal);
        C;

    private readonly FFI $ffi;

    /**
     * @throws AdapterUnavailableException
     */
    public function __construct(string $libraryPath)
    {
        if (!extension_loaded('ffi')) {
            throw new AdapterUnavailableException('The PHP extension "ffi" is not loaded');
        }

        if (!is_file($libraryPath)) {
            throw new AdapterUnavailableException(sprintf('libical not found at %s (set LIBICAL_PATH)', $libraryPath));
        }

        try {
            $this->ffi = FFI::cdef(self::C_DECLARATIONS, $libraryPath);
        } catch (FfiException $e) {
            throw new AdapterUnavailableException(sprintf('Cannot load libical 4.x from %s: %s', $libraryPath, $e->getMessage()), 0, $e);
        }

        $this->call('icalerror_set_errors_are_fatal', false);
    }

    /**
     * Uses the library at the path in the environment variable LIBICAL_PATH, or {@see self::DEFAULT_LIBRARY_PATH}.
     *
     * @throws AdapterUnavailableException
     */
    public static function fromEnvironment(): self
    {
        $path = getenv('LIBICAL_PATH');

        return new self(is_string($path) && $path !== '' ? $path : self::DEFAULT_LIBRARY_PATH);
    }

    /**
     * True when libical was built with ICU: RSCALE then supports all CLDR calendars, and sub-daily rules step
     * in elapsed time across DST transitions (without ICU they step in wall-clock time).
     */
    public function hasIcu(): bool
    {
        $calendars = $this->callForPointer('icalrecurrencetype_rscale_supported_calendars');

        try {
            return self::readInt($calendars, 'num_elements') > 1;
        } finally {
            $this->call('icalarray_free', $calendars);
        }
    }

    /**
     * The first $limit occurrences as libical computes them, in the form of the start: YYYYMMDD for a DATE start,
     * YYYYMMDDTHHMMSS for floating and zoned starts (local wall time), YYYYMMDDTHHMMSSZ for a UTC start.
     *
     * @return list<string>
     *
     * @throws LibicalException
     */
    public function expand(
        DtStart $dtStart,
        string $rrule,
        int $limit,
    ): array
    {
        $occurrences = [];

        foreach ($this->iterate($dtStart, $rrule, $limit) as $time) {
            $occurrences[] = self::format($time, $dtStart->kind === DtStartKind::Utc);
        }

        return $occurrences;
    }

    /**
     * The first $limit occurrences of a zoned or UTC start as UTC instants (YYYYMMDDTHHMMSSZ). libical itself
     * converts each local occurrence with icaltime_as_timet_with_zone().
     *
     * @return list<string>
     *
     * @throws LibicalException
     */
    public function expandToUtc(
        DtStart $dtStart,
        string $rrule,
        int $limit,
    ): array
    {
        if ($dtStart->kind === DtStartKind::Utc) {
            return $this->expand($dtStart, $rrule, $limit);
        }

        if ($dtStart->kind !== DtStartKind::Zoned) {
            throw new LogicException(sprintf('A %s start has no UTC instants', $dtStart->kind->name));
        }

        $zone = $this->getZone($dtStart);
        $instants = [];

        foreach ($this->iterate($dtStart, $rrule, $limit) as $time) {
            $unix = $this->call('icaltime_as_timet_with_zone', $time, $zone);

            if (!is_int($unix)) {
                throw new LogicException('icaltime_as_timet_with_zone() did not return an integer');
            }

            $instants[] = gmdate('Ymd\THis\Z', $unix);
        }

        return $instants;
    }

    /**
     * @return list<CData> struct icaltimetype values
     *
     * @throws LibicalException
     */
    private function iterate(
        DtStart $dtStart,
        string $rrule,
        int $limit,
    ): array
    {
        $start = $this->createStart($dtStart);

        $this->call('icalerror_clear_errno');
        $rule = $this->call('icalrecurrencetype_new_from_string', $rrule);

        if (!$rule instanceof CData) {
            throw $this->createException(sprintf('libical rejected the rule "%s"', $rrule));
        }

        try {
            $iterator = $this->call('icalrecur_iterator_new', $rule, $start);

            if (!$iterator instanceof CData) {
                throw $this->createException(sprintf('libical cannot iterate "%s" from %s', $rrule, $dtStart->toString()));
            }

            try {
                $times = [];

                while (count($times) < $limit) {
                    $time = $this->callForPointer('icalrecur_iterator_next', $iterator);

                    if ($this->call('icaltime_is_null_time', $time) === true) {
                        if ($this->getErrno() !== self::ICAL_NO_ERROR) {
                            throw $this->createException(sprintf('libical failed while iterating "%s" from %s', $rrule, $dtStart->toString()));
                        }

                        break;
                    }

                    $times[] = $time;
                }

                return $times;
            } finally {
                $this->call('icalrecur_iterator_free', $iterator);
            }
        } finally {
            $this->call('icalrecurrencetype_unref', $rule);
        }
    }

    /**
     * @throws LibicalException
     */
    private function createStart(DtStart $dtStart): CData
    {
        $start = $this->callForPointer('icaltime_from_string', $dtStart->value);

        if ($dtStart->kind !== DtStartKind::Zoned) {
            return $start;
        }

        return $this->callForPointer('icaltime_set_timezone', FFI::addr($start), $this->getZone($dtStart));
    }

    /**
     * @throws LibicalException
     */
    private function getZone(DtStart $dtStart): CData
    {
        $zone = $this->call('icaltimezone_get_builtin_timezone', (string) $dtStart->timeZone);

        if (!$zone instanceof CData) {
            throw new LibicalException(sprintf('libical does not know the time zone "%s"', (string) $dtStart->timeZone), 'UNKNOWN TIME ZONE');
        }

        return $zone;
    }

    private function getErrno(): int
    {
        $errno = $this->callForPointer('icalerror_icalerrno');
        $value = $errno[0];

        if (!is_int($value)) {
            throw new LogicException('icalerrno is not an integer');
        }

        return $value;
    }

    private function createException(string $message): LibicalException
    {
        $error = $this->call('icalerror_strerror', $this->getErrno());
        $error = is_string($error) ? $error : 'unknown error';

        return new LibicalException(sprintf('%s: %s', $message, $error), $error);
    }

    private static function format(
        CData $time,
        bool $utc,
    ): string
    {
        $date = sprintf('%04d%02d%02d', self::readInt($time, 'year'), self::readInt($time, 'month'), self::readInt($time, 'day'));

        if (self::readInt($time, 'is_date') === 1) {
            return $date;
        }

        return sprintf(
            '%sT%02d%02d%02d%s',
            $date,
            self::readInt($time, 'hour'),
            self::readInt($time, 'minute'),
            self::readInt($time, 'second'),
            $utc ? 'Z' : '',
        );
    }

    private function call(
        string $function,
        mixed ...$arguments,
    ): mixed
    {
        return $this->ffi->{$function}(...$arguments); // @phpstan-ignore method.dynamicName (libical functions are declared in C_DECLARATIONS, not in PHP)
    }

    private function callForPointer(
        string $function,
        mixed ...$arguments,
    ): CData
    {
        $result = $this->call($function, ...$arguments);

        if (!$result instanceof CData) {
            throw new LogicException(sprintf('%s() returned no value', $function));
        }

        return $result;
    }

    private static function readInt(
        CData $struct,
        string $field,
    ): int
    {
        $value = $struct->{$field}; // @phpstan-ignore property.dynamicName (struct fields are declared in C_DECLARATIONS)

        if (!is_int($value)) {
            throw new LogicException(sprintf('Struct field %s is not an integer', $field));
        }

        return $value;
    }

}
