#!/bin/sh
# Runs the differential test suite (libical, python-dateutil, zoneinfo) in its Docker image.
# The tests run on a copy of the repository, so the vendor directory of the host does not change.
# Arguments go to PHPUnit. Set IMAGE to use an image that is already built (CI builds it with a layer cache).
set -eu

root="$(cd "$(dirname "$0")/../.." && pwd)"
image="${IMAGE:-shipmonk-rrule-differential}"

if [ -z "${IMAGE:-}" ]; then
    docker build --tag "$image" "$root/docker/differential"
fi

docker run --rm --user "$(id -u):$(id -g)" --volume "$root:/src:ro" --env DIFFERENTIAL_REPORT "$image" sh -euc '
    mkdir -p /tmp/rrule/cache
    tar -C /src --exclude=./.git --exclude=./vendor --exclude=./cache --exclude=./tools --exclude=./composer.lock -cf - . | tar -C /tmp/rrule -xf -
    cd /tmp/rrule
    composer install --no-progress --no-interaction --quiet
    vendor/bin/phpunit --testsuite differential --fail-on-skipped "$@"
' sh "$@"
