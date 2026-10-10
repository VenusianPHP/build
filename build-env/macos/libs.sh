#!/bin/sh
# Builds the library set a macos-arm64 build links, at the macOS 14 floor.
# Usage: libs.sh <prefix>. Writes <prefix>/.complete last; venusian build rebuilds a prefix
# without it.
#
# A library the macOS SDK carries (zlib, libxml2, sqlite3, curl, iconv, bzip2, libffi, libedit,
# libxslt) is the system's on every Mac at the floor: this script writes pkg-config files for
# those and builds none. Every other library is built static from a pinned, checksummed archive,
# LDAP among them: the system's OpenLDAP 2.4.28 exports none of ldap_destroy and the sort, VLV
# and password-policy control functions PHP 8.4's ldap calls. The Vulkan loader stays a dylib, with MoltenVK as its driver: the .app carries both in
# Contents/Frameworks. pkg-config and CMake see this prefix and the SDK only, never Homebrew.
set -eu
PREFIX=$1
WORK="$PREFIX.work"
SDK=$(xcrun --show-sdk-path)
JOBS=$(sysctl -n hw.ncpu)
export MACOSX_DEPLOYMENT_TARGET=14.0
export CFLAGS="-mmacosx-version-min=14.0 -arch arm64 -O2"
export CXXFLAGS="$CFLAGS"
export LDFLAGS="-mmacosx-version-min=14.0 -arch arm64"
export PKG_CONFIG_LIBDIR="$PREFIX/lib/pkgconfig"
# A shell's own flags (Homebrew's caveats export CPPFLAGS and LIBRARY_PATH) would mix its headers
# and libraries into the build where no link check sees them.
unset CPPFLAGS CPATH C_INCLUDE_PATH CPLUS_INCLUDE_PATH OBJC_INCLUDE_PATH LIBRARY_PATH LIBS CC CXX OBJC OBJCFLAGS PKG_CONFIG_PATH

# fetch <url> <sha256> <file>
fetch() {
    curl -fsSL -o "$3" "$1"
    echo "$2  $3" | shasum -a 256 -c - > /dev/null || { echo "libs.sh: $3 does not match its pinned sha256 ($1)" >&2; exit 1; }
}

# cm <cmake args>: configure with the floor, the prefix, and Homebrew out of sight.
cm() {
    cmake "$@" -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="$PREFIX" -DCMAKE_INSTALL_LIBDIR=lib \
        -DCMAKE_PREFIX_PATH="$PREFIX" -DCMAKE_OSX_DEPLOYMENT_TARGET=14.0 -DCMAKE_OSX_ARCHITECTURES=arm64 \
        "-DCMAKE_IGNORE_PREFIX_PATH=/opt/homebrew;/usr/local" -DCMAKE_FIND_FRAMEWORK=LAST -DCMAKE_POLICY_VERSION_MINIMUM=3.5
}

# step <name>: runs build_<name> in its own shell under set -e, output to <name>.log; a failure
# prints the log's tail and stops.
step() {
    set +e
    ( set -e; "build_$1" ) > "$WORK/$1.log" 2>&1
    STATUS=$?
    set -e
    if [ "$STATUS" -ne 0 ]; then
        tail -30 "$WORK/$1.log" >&2
        echo "libs.sh: $1 failed; full log: $WORK/$1.log" >&2
        exit 1
    fi
}

# The SDK's libraries: pkg-config files only, versions read from the SDK's own headers.
ver() { sed -n "s/^#define $2 *\"\([^\"]*\)\".*/\1/p" "$SDK/usr/include/$1" | head -1; }
pc() { printf 'Name: %s\nDescription: %s from the macOS SDK\nVersion: %s\nCflags: %s\nLibs: %s\n' "$1" "$1" "$2" "$3" "$4" > "$PREFIX/lib/pkgconfig/$1.pc"; }
sdk_pc() {
    pc zlib "$(ver zlib.h ZLIB_VERSION)" "" "-lz"
    pc libxml-2.0 "$(ver libxml2/libxml/xmlversion.h LIBXML_DOTTED_VERSION)" "-I$SDK/usr/include/libxml2" "-lxml2"
    pc sqlite3 "$(ver sqlite3.h SQLITE_VERSION)" "" "-lsqlite3"
    pc libcurl "$(ver curl/curlver.h LIBCURL_VERSION)" "" "-lcurl"
    pc libxslt "$(ver libxslt/xsltconfig.h LIBXSLT_DOTTED_VERSION)" "-I$SDK/usr/include/libxml2" "-lxslt -lxml2"
    pc libexslt "$(ver libexslt/exsltconfig.h LIBEXSLT_DOTTED_VERSION)" "-I$SDK/usr/include/libxml2" "-lexslt -lxslt -lxml2"
    pc libffi 3.4 "-I$SDK/usr/include/ffi" "-lffi"
    pc libedit 3.0 "" "-ledit"
}

build_openssl() {
    fetch https://github.com/openssl/openssl/releases/download/openssl-3.5.4/openssl-3.5.4.tar.gz 967311f84955316969bdb1d8d4b983718ef42338639c621ec4c34fddef355e99 openssl.tar.gz
    tar -xzf openssl.tar.gz
    cd openssl-3.5.4
    # Providers built in and no engines: nothing loads from a directory, so none of this machine's is compiled in.
    ./Configure darwin64-arm64-cc no-shared no-tests no-docs no-module no-engine --prefix="$PREFIX" --libdir=lib --openssldir=/etc/ssl
    make -j"$JOBS" ENGINESDIR=/var/empty MODULESDIR=/var/empty
    make install_sw ENGINESDIR=/var/empty MODULESDIR=/var/empty
}

# The client libraries only (liblber, libldap), with TLS from the OpenSSL above.
build_openldap() {
    fetch https://www.openldap.org/software/download/OpenLDAP/openldap-release/openldap-2.6.15.tgz bc91225dbfc50354033b1303bc91d1a7f6ddd1dc32fac950d79c28fe66d6bca8 openldap.tgz
    tar -xzf openldap.tgz
    cd openldap-2.6.15
    # The user's /etc/openldap/ldap.conf, as every LDAP client on the Mac reads it; installed into the prefix.
    CPPFLAGS="-I$PREFIX/include" LDFLAGS="$LDFLAGS -L$PREFIX/lib" ./configure --prefix="$PREFIX" --disable-shared --enable-static \
        --disable-slapd --without-cyrus-sasl --with-tls=openssl --sysconfdir=/etc --localstatedir=/var
    make -j"$JOBS" -C include
    make -j"$JOBS" -C libraries
    make -C include install
    make -C libraries install sysconfdir="$PREFIX/etc" localstatedir="$PREFIX/var"
}

build_oniguruma() {
    fetch https://github.com/kkos/oniguruma/releases/download/v6.9.10/onig-6.9.10.tar.gz 2a5cfc5ae259e4e97f86b68dfffc152cdaffe94e2060b770cb827238d769fc05 onig.tar.gz
    tar -xzf onig.tar.gz
    cd onig-6.9.10
    ./configure --prefix="$PREFIX" --disable-shared --enable-static
    make -j"$JOBS"
    make install
}

build_libjpeg() {
    fetch https://github.com/libjpeg-turbo/libjpeg-turbo/releases/download/3.2.0/libjpeg-turbo-3.2.0.tar.gz 6f30092cef9fb839779646608f4ee14ae3cbac989c47fa05e841b0841f09878e libjpeg-turbo.tar.gz
    tar -xzf libjpeg-turbo.tar.gz
    cm -S libjpeg-turbo-3.2.0 -B libjpeg-build -DENABLE_SHARED=OFF -DENABLE_STATIC=ON -DWITH_TURBOJPEG=OFF
    cmake --build libjpeg-build -j "$JOBS"
    cmake --install libjpeg-build
}

build_libpng() {
    fetch https://download.sourceforge.net/libpng/libpng-1.6.59.tar.xz d80dd2a38a37f803cb9b6ac7b14bd6e74ddc3b654780a8380bdf93523fdb4389 libpng.tar.xz
    tar -xJf libpng.tar.xz
    cd libpng-1.6.59
    ./configure --prefix="$PREFIX" --disable-shared --enable-static
    make -j"$JOBS"
    make install
}

build_libtiff() {
    fetch https://download.osgeo.org/libtiff/tiff-4.7.1.tar.xz b92017489bdc1db3a4c97191aa4b75366673cb746de0dce5d7a749d5954681ba libtiff.tar.xz
    tar -xJf libtiff.tar.xz
    cm -S tiff-4.7.1 -B tiff-build -DBUILD_SHARED_LIBS=OFF -Dtiff-tools=OFF -Dtiff-tests=OFF -Dtiff-contrib=OFF -Dtiff-docs=OFF \
        -Dtiff-cxx=OFF -Djbig=OFF -Dlerc=OFF -Dlzma=OFF -Dzstd=OFF -Dwebp=OFF -Dlibdeflate=OFF
    cmake --build tiff-build -j "$JOBS"
    cmake --install tiff-build
}

build_glfw() {
    fetch https://github.com/glfw/glfw/releases/download/3.4/glfw-3.4.zip b5ec004b2712fd08e8861dc271428f048775200a2df719ccf575143ba749a3e9 glfw.zip
    unzip -q glfw.zip
    cm -S glfw-3.4 -B glfw-build -DBUILD_SHARED_LIBS=OFF -DGLFW_BUILD_EXAMPLES=OFF -DGLFW_BUILD_TESTS=OFF -DGLFW_BUILD_DOCS=OFF
    cmake --build glfw-build -j "$JOBS"
    cmake --install glfw-build
}

build_sdl3() {
    fetch https://github.com/libsdl-org/SDL/releases/download/release-3.4.18/SDL3-3.4.18.tar.gz 9c75cf16330322c217dedd2e0609f1124f1b54b8633e763467b4684d0f4334a3 sdl3.tar.gz
    tar -xzf sdl3.tar.gz
    cm -S SDL3-3.4.18 -B sdl3-build -DSDL_SHARED=OFF -DSDL_STATIC=ON -DSDL_TESTS=OFF -DSDL_TEST_LIBRARY=OFF -DSDL_EXAMPLES=OFF
    cmake --build sdl3-build -j "$JOBS"
    cmake --install sdl3-build
}

build_libusb() {
    fetch https://github.com/libusb/libusb/releases/download/v1.0.30/libusb-1.0.30.tar.bz2 fea36f34f9156400209595e300840767ab1a385ede1dc7ee893015aea9c6dbaf libusb.tar.bz2
    tar -xjf libusb.tar.bz2
    cd libusb-1.0.30
    ./configure --prefix="$PREFIX" --disable-shared --enable-static
    make -j"$JOBS"
    make install
}

# libftdi also links a shared library (discarded below) against the static libusb, which needs libusb's frameworks.
build_libftdi() {
    fetch https://www.intra2net.com/en/developer/libftdi/download/libftdi1-1.5.tar.bz2 7c7091e9c86196148bd41177b4590dccb1510bfe6cea5bf7407ff194482eb049 libftdi.tar.bz2
    tar -xjf libftdi.tar.bz2
    cm -S libftdi1-1.5 -B ftdi-build -DSTATICLIBS=ON -DFTDIPP=OFF -DPYTHON_BINDINGS=OFF -DLINK_PYTHON_LIBRARY=OFF \
        -DEXAMPLES=OFF -DDOCUMENTATION=OFF -DFTDI_EEPROM=OFF -DBUILD_TESTS=OFF \
        "-DCMAKE_SHARED_LINKER_FLAGS=-lobjc -framework IOKit -framework CoreFoundation -framework Security"
    cmake --build ftdi-build -j "$JOBS"
    cmake --install ftdi-build
}

build_vulkan_headers() {
    fetch https://github.com/KhronosGroup/Vulkan-Headers/archive/refs/tags/v1.4.309.tar.gz 437925ada160d86ed763d29dcb9318c1bb0d024d7deaf77bc7c170b8eb6b6f10 vulkan-headers.tar.gz
    tar -xzf vulkan-headers.tar.gz
    cm -S Vulkan-Headers-1.4.309 -B vulkan-headers-build
    cmake --install vulkan-headers-build
}

build_vulkan_loader() {
    fetch https://github.com/KhronosGroup/Vulkan-Loader/archive/refs/tags/v1.4.309.tar.gz 3e4085a55f6e356fe9dbd47e6dc762be732790add3532943da824b3e8c062827 vulkan-loader.tar.gz
    tar -xzf vulkan-loader.tar.gz
    cm -S Vulkan-Loader-1.4.309 -B vulkan-loader-build -DVULKAN_HEADERS_INSTALL_DIR="$PREFIX" -DBUILD_TESTS=OFF -DCMAKE_INSTALL_NAME_DIR=@rpath
    cmake --build vulkan-loader-build -j "$JOBS"
    cmake --install vulkan-loader-build
}

build_moltenvk() {
    fetch https://github.com/KhronosGroup/MoltenVK/releases/download/v1.4.2/MoltenVK-macos.tar f95765a6229cb7b915990a2890ce12ebe36a730b021545d3d52ae69ce4c4024e moltenvk.tar
    tar -xf moltenvk.tar
    SRC=MoltenVK/MoltenVK/dynamic/dylib/macOS
    if [ "$(lipo -archs "$SRC/libMoltenVK.dylib")" = arm64 ]; then
        cp "$SRC/libMoltenVK.dylib" "$PREFIX/lib/libMoltenVK.dylib"
    else
        lipo -thin arm64 "$SRC/libMoltenVK.dylib" -output "$PREFIX/lib/libMoltenVK.dylib"
    fi
    mkdir -p "$PREFIX/share/vulkan/icd.d"
    sed 's|"library_path" *: *"[^"]*"|"library_path" : "../../../lib/libMoltenVK.dylib"|' "$SRC/MoltenVK_icd.json" > "$PREFIX/share/vulkan/icd.d/MoltenVK_icd.json"
}

rm -rf "$PREFIX" "$WORK"
mkdir -p "$PREFIX/lib/pkgconfig" "$WORK"
cd "$WORK"
sdk_pc
for NAME in openssl openldap oniguruma libjpeg libpng libtiff glfw sdl3 libusb libftdi vulkan_headers vulkan_loader moltenvk; do
    step "$NAME"
done

# A stray dylib would be linked over its .a; only the Vulkan pair stays dynamic.
find "$PREFIX/lib" -name '*.dylib' ! -name 'libvulkan*' ! -name 'libMoltenVK.dylib' -exec rm -f {} +

# PHP's configure asks pkg-config without --static: a static library's own needs move where it looks.
for PC in "$PREFIX"/lib/pkgconfig/*.pc; do
    # libpng.pc links to libpng16.pc, which the loop edits itself.
    if [ -L "$PC" ]; then
        continue
    fi
    PRIVATE=$(sed -n 's/^Libs\.private: *//p' "$PC")
    REQUIRES=$(sed -n 's/^Requires\.private: *//p' "$PC")
    if [ -n "$PRIVATE" ]; then
        sed -i '' -e "s|^Libs: \(.*\)$|Libs: \1 $PRIVATE|" -e '/^Libs\.private:/d' "$PC"
    fi
    if [ -n "$REQUIRES" ]; then
        if grep -q '^Requires:' "$PC"; then
            sed -i '' -e "s|^Requires: \(.*\)$|Requires: \1, $REQUIRES|" "$PC"
        else
            echo "Requires: $REQUIRES" >> "$PC"
        fi
        sed -i '' -e '/^Requires\.private:/d' "$PC"
    fi
done

for MODULE in openssl ldap oniguruma libjpeg libpng libtiff-4 glfw3 sdl3 libusb-1.0 libftdi1 vulkan zlib libxml-2.0 sqlite3 libcurl libxslt libexslt libffi libedit; do
    pkg-config --exists "$MODULE" || { echo "libs.sh: pkg-config cannot find $MODULE in $PREFIX" >&2; exit 1; }
done

cd /
rm -rf "$WORK"
date -u '+%Y-%m-%dT%H:%M:%SZ' > "$PREFIX/.complete"
