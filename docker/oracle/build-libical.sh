#!/bin/sh
# Builds libical from a source tarball twice: /opt/libical-noicu and /opt/libical-icu.
# Usage: build-libical.sh <tarball-url> <sha256>
set -eu

url="$1"
sha256="$2"
work="$(mktemp -d)"

curl -fsSL --retry 3 -o "$work/libical.tar.gz" "$url"
echo "$sha256  $work/libical.tar.gz" | sha256sum -c -
mkdir "$work/src"
tar -xzf "$work/libical.tar.gz" -C "$work/src" --strip-components=1

build() {
    prefix="$1"
    shift
    cmake -S "$work/src" -B "$work/build-$(basename "$prefix")" \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_INSTALL_PREFIX="$prefix" \
        -DCMAKE_INSTALL_LIBDIR=lib \
        -DCMAKE_DISABLE_FIND_PACKAGE_BerkeleyDB=ON \
        -DLIBICAL_ENABLE_BUILTIN_TZDATA=OFF \
        -DLIBICAL_STATIC=OFF \
        -DLIBICAL_CXX_BINDINGS=OFF \
        -DLIBICAL_JAVA_BINDINGS=OFF \
        -DLIBICAL_GLIB=OFF \
        -DLIBICAL_GOBJECT_INTROSPECTION=OFF \
        -DLIBICAL_GLIB_VAPI=OFF \
        -DLIBICAL_BUILD_DOCS=OFF \
        -DLIBICAL_BUILD_EXAMPLES=OFF \
        -DLIBICAL_BUILD_TESTING=OFF \
        "$@"
    cmake --build "$work/build-$(basename "$prefix")" -j "$(nproc)"
    cmake --install "$work/build-$(basename "$prefix")"
    find "$prefix" -mindepth 1 -maxdepth 1 ! -name lib -exec rm -rf {} +
    find "$prefix/lib" -mindepth 1 -maxdepth 1 ! -name 'libical.so*' -exec rm -rf {} +
}

# libical has no ICU switch; ICU is used whenever CMake finds it.
build /opt/libical-noicu -DCMAKE_DISABLE_FIND_PACKAGE_ICU=ON
build /opt/libical-icu -DCMAKE_REQUIRE_FIND_PACKAGE_ICU=ON

if ldd /opt/libical-noicu/lib/libical.so | grep -q libicu; then
    echo 'libical-noicu links ICU' >&2
    exit 1
fi

if ! ldd /opt/libical-icu/lib/libical.so | grep -q libicui18n; then
    echo 'libical-icu does not link ICU' >&2
    exit 1
fi

rm -rf "$work"
