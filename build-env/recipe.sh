#!/bin/sh
# Compiles PHP with the Venusian SAPI and the app's extensions, once per set.
# Inputs in /work/in: php-src.tar.xz, sapi.tar.gz, ext/<name>.zip, set.hash,
# php.version, configure.args (one per line), extensions.list (name<TAB>build path),
# apt.build (one package per line).
# Output: /work/out/venusian. A build dir whose set.hash matches is reused; a new
# set replaces the old one, so an app's volume holds one compiled tree.
# Failures print the log's tail on stderr, which docker hands back as the error.
set -eu
cd /work
HASH=$(cat in/set.hash)
VERSION=$(cat in/php.version)
BUILD=build/$HASH
mkdir -p out

if [ -x "$BUILD/php-src/sapi/venusian/venusian" ] && [ "$(cat "$BUILD/set.hash" 2>/dev/null)" = "$HASH" ]; then
    echo "reusing the runtime compiled for set $HASH"
    cp "$BUILD/php-src/sapi/venusian/venusian" out/venusian
    exit 0
fi

sh /work/apt-build.sh

rm -rf build
mkdir -p "$BUILD"
cd "$BUILD"
tar -xJf /work/in/php-src.tar.xz
mv "php-$VERSION" php-src
mkdir -p php-src/sapi/venusian
tar -xzf /work/in/sapi.tar.gz --strip-components=1 -C php-src/sapi/venusian

while IFS="$(printf '\t')" read -r NAME SUBDIR; do
    rm -rf "ext-$NAME"
    mkdir "ext-$NAME"
    unzip -q "/work/in/ext/$NAME.zip" -d "ext-$NAME"
    TOP=$(find "ext-$NAME" -mindepth 1 -maxdepth 1 -type d | head -1)
    rm -rf "php-src/ext/$NAME"
    cp -R "$TOP/$SUBDIR" "php-src/ext/$NAME"
done < /work/in/extensions.list

cd php-src
./buildconf --force > ../buildconf.log 2>&1 || { tail -20 ../buildconf.log >&2; exit 1; }
# buildconf exits 0 when m4 fails on an extension's config.m4; --force removed the old configure first.
if [ ! -x configure ]; then
    tail -20 ../buildconf.log >&2
    exit 1
fi
# shellcheck disable=SC2046
./configure $(tr '\n' ' ' < /work/in/configure.args) > ../configure.log 2>&1 || { tail -40 ../configure.log >&2; exit 1; }
make -j"$(nproc)" > ../make.log 2>&1 || { grep -n -E "error:|Error [0-9]" ../make.log | head -40 >&2; exit 1; }
cp /work/in/set.hash ../set.hash
cp sapi/venusian/venusian /work/out/venusian
./sapi/venusian/venusian --venusian-version
