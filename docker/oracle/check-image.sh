#!/bin/sh
# Smoke test of the oracle image: FFI loads both libical builds, Python has zoneinfo and dateutil.
set -eu

for lib in /opt/libical-noicu/lib/libical.so /opt/libical-icu/lib/libical.so; do
    php -r '
        $ffi = FFI::cdef("void *icalrecurrencetype_new_from_string(const char *str); void icalrecurrencetype_unref(void *recur);", $argv[1]);
        $rule = $ffi->icalrecurrencetype_new_from_string("FREQ=DAILY;COUNT=2");
        if (FFI::isNull($rule)) {
            fwrite(STDERR, "libical cannot parse a rule: " . $argv[1] . "\n");
            exit(1);
        }
        $ffi->icalrecurrencetype_unref($rule);
        echo "FFI OK: ", $argv[1], "\n";
    ' "$lib"
done

"$ORACLE_PYTHON" -I -c '
import dateutil, zoneinfo
from importlib.metadata import version
zoneinfo.ZoneInfo("Europe/Prague")
assert version("python-dateutil") == "4.0.0", version("python-dateutil")
print("Python OK:", version("python-dateutil"))
'

composer --version
