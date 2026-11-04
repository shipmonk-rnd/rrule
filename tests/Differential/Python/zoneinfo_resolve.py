"""Resolve local wall times to Unix times with zoneinfo and fold=0.

PEP 495 fold=0 gives the earlier offset in an overlap and the offset from before
the transition in a gap. RFC 5545 section 3.3.5 requires the same.

Run with "python3 -I". The input on stdin is JSON in one of two forms:

    [{"zone": "Europe/Prague", "local": "2026-10-25T02:30:00"}, ...]

    {"tzif": {"Europe/Prague": "<base64 TZif data>"}, "points": [<as above>]}

The second form loads the zones in "tzif" from the given data, not from the
TZ path. The output is a JSON list with one Unix time per point.
"""

import base64
import io
import json
import sys
from datetime import datetime
from zoneinfo import ZoneInfo


def main():
    request = json.load(sys.stdin)

    if isinstance(request, list):
        tzif, points = {}, request
    else:
        tzif, points = request.get("tzif", {}), request["points"]

    zones = {
        name: ZoneInfo.from_file(io.BytesIO(base64.b64decode(data)), key=name)
        for name, data in tzif.items()
    }

    result = []
    for point in points:
        name = point["zone"]
        if name not in zones:
            zones[name] = ZoneInfo(name)

        local = datetime.fromisoformat(point["local"]).replace(tzinfo=zones[name], fold=0)
        result.append(int(local.timestamp()))

    json.dump(result, sys.stdout)


main()
