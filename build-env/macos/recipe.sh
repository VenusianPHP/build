#!/bin/sh
# Compiles PHP with the Venusian SAPI and the app's extensions natively for macos-arm64 at the
# macOS 14 floor, against the library prefix libs.sh built.
# Usage: recipe.sh <stage> <prefix>. Inputs in <stage>/in: php-src.tar.xz, sapi.tar.gz,
# ext/<name>.zip, php.version, configure.args (one per line), extensions.list
# (name<TAB>build path). Output: <stage>/out/venusian. A binary that links anything but the OS
# and the bundle's Frameworks is refused. Failures print the log's tail on stderr.
set -eu
STAGE=$1
PREFIX=$2
VERSION=$(cat "$STAGE/in/php.version")
JOBS=$(sysctl -n hw.ncpu)
export MACOSX_DEPLOYMENT_TARGET=14.0
export CFLAGS="-mmacosx-version-min=14.0 -arch arm64 -O2"
export CXXFLAGS="$CFLAGS"
export LDFLAGS="-mmacosx-version-min=14.0 -arch arm64 -Wl,-rpath,@executable_path/../Frameworks"
export PKG_CONFIG_LIBDIR="$PREFIX/lib/pkgconfig"
# A shell's own flags (Homebrew's caveats export CPPFLAGS and LIBRARY_PATH) would mix its headers
# and libraries into the build where no link check sees them.
unset CPPFLAGS CPATH C_INCLUDE_PATH CPLUS_INCLUDE_PATH OBJC_INCLUDE_PATH LIBRARY_PATH LIBS CC CXX OBJC OBJCFLAGS PKG_CONFIG_PATH

mkdir -p "$STAGE/out" "$STAGE/build"
cd "$STAGE/build"
tar -xJf ../in/php-src.tar.xz
mv "php-$VERSION" php-src
mkdir -p php-src/sapi/venusian
tar -xzf ../in/sapi.tar.gz --strip-components=1 -C php-src/sapi/venusian

while IFS="$(printf '\t')" read -r NAME SUBDIR; do
    mkdir "ext-$NAME"
    unzip -q "../in/ext/$NAME.zip" -d "ext-$NAME"
    TOP=$(find "ext-$NAME" -mindepth 1 -maxdepth 1 -type d | head -1)
    rm -rf "php-src/ext/$NAME"
    cp -R "$TOP/$SUBDIR" "php-src/ext/$NAME"
done < ../in/extensions.list

cd php-src
./buildconf --force > ../buildconf.log 2>&1 || { tail -20 ../buildconf.log >&2; exit 1; }
# buildconf exits 0 when m4 fails on an extension's config.m4; --force removed the old configure first.
if [ ! -x configure ]; then
    tail -20 ../buildconf.log >&2
    exit 1
fi
# shellcheck disable=SC2046
./configure $(tr '\n' ' ' < ../../in/configure.args) > ../configure.log 2>&1 || { tail -40 ../configure.log >&2; exit 1; }
if ! make -j"$JOBS" > ../make.log 2>&1; then
    grep -n -E "error:|Error [0-9]|Undefined symbols|referenced from|ld: " ../make.log | head -60 >&2 || tail -40 ../make.log >&2
    exit 1
fi
# A static library built for a newer macOS links without error and fails on a Mac at the floor.
NEWER=$(grep "was built for newer 'macOS' version" ../make.log || true)
if [ -n "$NEWER" ]; then
    echo "The link took objects built for a newer macOS than 14.0:" >&2
    echo "$NEWER" | head -10 >&2
    exit 1
fi

BIN=sapi/venusian/venusian
# The OS's libraries and the bundle's Frameworks only: Homebrew, /usr/local or the prefix would not exist on another Mac.
LEAKS=$(otool -L "$BIN" | tail -n +2 | awk '{print $1}' | grep -v -E '^(/System/Library/|/usr/lib/|@rpath/)' || true)
if [ -n "$LEAKS" ]; then
    echo "The binary links libraries another Mac does not have:" >&2
    echo "$LEAKS" >&2
    exit 1
fi
for LIB in $(otool -L "$BIN" | tail -n +2 | awk '{print $1}' | sed -n 's|^@rpath/||p'); do
    if [ ! -f "$PREFIX/lib/$LIB" ]; then
        echo "The binary links @rpath/$LIB, which the library set does not have" >&2
        exit 1
    fi
done
RPATHS=$(otool -l "$BIN" | awk '/LC_RPATH/ { found = 1 } found == 1 && $1 == "path" { print $2; found = 0 }' | grep -v -x '@executable_path/../Frameworks' || true)
if [ -n "$RPATHS" ]; then
    echo "The binary searches for libraries outside the bundle's Frameworks:" >&2
    echo "$RPATHS" >&2
    exit 1
fi
MINOS=$(otool -l "$BIN" | awk '/LC_BUILD_VERSION/ { found = 1 } found == 1 { if ($1 == "minos") { print $2; exit } }')
if [ "$MINOS" != "14.0" ]; then
    echo "The binary's minimum macOS is $MINOS, not 14.0" >&2
    exit 1
fi

cp "$BIN" ../../out/venusian
DYLD_LIBRARY_PATH="$PREFIX/lib" ../../out/venusian --venusian-version
