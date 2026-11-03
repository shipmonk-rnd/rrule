# syntax=docker/dockerfile:1

# Differential tests of shipmonk/rrule: libical (PHP FFI), python-dateutil and Python zoneinfo.
# Build: docker build -t shipmonk-rrule-differential docker/differential

# php:8.1-cli = PHP 8.1.34 on Debian 13 (trixie). Both stages use it, so glibc and ICU versions match.
ARG PHP_IMAGE=php:8.1-cli@sha256:76e563191d1ade120313a8736df24154d21da5155c0756f147c0b01bd19d9087

FROM ${PHP_IMAGE} AS libical

# The libical 4.0 branch at the merge of PR #1467 (libical issue #1450, DST gap drift in ICU builds).
# v4.0.7 (2026-10-18) does not contain this fix. Archives of a commit are stable, so the hash is pinned.
# To build the v4.0.7 release instead:
#   --build-arg LIBICAL_URL=https://github.com/libical/libical/releases/download/v4.0.7/libical-4.0.7.tar.gz
#   --build-arg LIBICAL_SHA256=47c05bc96e41416578cf1803bb2ba3f8d292cb17d5ac2fca539dee922bae8a94
ARG LIBICAL_URL=https://github.com/libical/libical/archive/cdf7f5cb4c07a28449a1b326ee795a5039bf1487.tar.gz
ARG LIBICAL_SHA256=cade2de0447477cff4fc5d0ab992b50311f58dfd5d09ad8a3cb6177c9ada1593

RUN apt-get update \
    && apt-get install -y --no-install-recommends cmake perl libicu-dev \
    && rm -rf /var/lib/apt/lists/*

COPY build-libical.sh /usr/local/bin/build-libical
RUN build-libical "$LIBICAL_URL" "$LIBICAL_SHA256"

FROM ${PHP_IMAGE}

RUN set -eux; \
    apt-get update; \
    icu="$(apt-cache depends libicu-dev | sed -n 's/^ *Depends: \(libicu[0-9][0-9]*\)$/\1/p')"; \
    apt-get install -y --no-install-recommends "$icu" libffi-dev python3 python3-venv tzdata unzip; \
    docker-php-ext-install -j"$(nproc)" ffi; \
    # PHP bundles an older time zone database; use the same IANA release as libical (system zoneinfo) and Python
    # (tzdata in requirements.txt), so that the tests compare the engines, not two tzdata versions.
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install timezonedb-2026.6; \
    docker-php-ext-enable timezonedb; \
    php -r 'exit(timezone_version_get() === "2026.6" ? 0 : 1);'; \
    apt-get purge -y --auto-remove libffi-dev $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*; \
    printf 'ffi.enable=1\nmemory_limit=1G\n' > "$PHP_INI_DIR/conf.d/zz-differential.ini"

COPY requirements.txt /tmp/differential-requirements.txt
RUN python3 -m venv /opt/differential-venv \
    && /opt/differential-venv/bin/pip install --no-cache-dir --disable-pip-version-check --require-hashes --only-binary :all: -r /tmp/differential-requirements.txt \
    && rm /tmp/differential-requirements.txt

COPY --from=composer/composer:2.11.1-bin /composer /usr/local/bin/composer
COPY --from=libical /opt/libical-noicu /opt/libical-noicu
COPY --from=libical /opt/libical-icu /opt/libical-icu
COPY check-image.sh /usr/local/bin/check-differential-image

ENV LIBICAL_PATH=/opt/libical-noicu/lib/libical.so \
    DIFFERENTIAL_PYTHON=/opt/differential-venv/bin/python3 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN check-differential-image

WORKDIR /app
