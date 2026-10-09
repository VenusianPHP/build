#!/bin/sh
# Lays out and builds the .deb. Inputs in /work/in/deb: control (without Depends),
# kebab, id, arch, version, app.phar, optional desktop, metainfo.xml, icon.png + icon.size,
# copyright. The binary is /work/out/venusian. Output: /work/out/<kebab>_<version>_<arch>.deb
set -eu
cd /work
D=in/deb
KEBAB=$(cat $D/kebab); ID=$(cat $D/id); ARCH=$(cat $D/arch); VERSION=$(cat $D/version)
ROOT=pkg/$KEBAB
rm -rf pkg
mkdir -p "$ROOT/DEBIAN" "$ROOT/usr/lib/$KEBAB" "$ROOT/usr/bin" "$ROOT/usr/share/doc/$KEBAB"

cp out/venusian "$ROOT/usr/lib/$KEBAB/$KEBAB"
strip --strip-unneeded "$ROOT/usr/lib/$KEBAB/$KEBAB"
cp $D/app.phar "$ROOT/usr/lib/$KEBAB/$KEBAB.phar"
chmod 0755 "$ROOT/usr/lib/$KEBAB/$KEBAB"; chmod 0644 "$ROOT/usr/lib/$KEBAB/$KEBAB.phar"
ln -s "../lib/$KEBAB/$KEBAB" "$ROOT/usr/bin/$KEBAB"
cp $D/copyright "$ROOT/usr/share/doc/$KEBAB/copyright"

if [ -f $D/desktop ]; then
    mkdir -p "$ROOT/usr/share/applications" "$ROOT/usr/share/metainfo"
    cp $D/desktop "$ROOT/usr/share/applications/$ID.desktop"
    cp $D/metainfo.xml "$ROOT/usr/share/metainfo/$ID.metainfo.xml"
fi
if [ -f $D/icon.png ]; then
    # Every hicolor size up to 512 px the source covers; never upscaled.
    SOURCE=$(cat $D/icon.size)
    for SIZE in 16 22 24 32 48 64 128 256 512; do
        if [ "$SIZE" -le "$SOURCE" ]; then
            mkdir -p "$ROOT/usr/share/icons/hicolor/${SIZE}x${SIZE}/apps"
            convert $D/icon.png -resize "${SIZE}x${SIZE}" "$ROOT/usr/share/icons/hicolor/${SIZE}x${SIZE}/apps/$ID.png"
        fi
    done
fi

# Depends from what the binary links: dpkg-shlibdeps wants a debian/control beside it.
mkdir -p pkg/debian
printf 'Source: %s\nMaintainer: venusian build <build@venusian.local>\n\nPackage: %s\nArchitecture: %s\nDescription: %s\n' "$KEBAB" "$KEBAB" "$ARCH" "$KEBAB" > pkg/debian/control
(cd pkg && dpkg-shlibdeps -O "$KEBAB/usr/lib/$KEBAB/$KEBAB") > pkg/shlibs 2> pkg/shlibs.err || { cat pkg/shlibs.err >&2; exit 1; }
DEPENDS=$(sed -n 's/^shlibs:Depends=//p' pkg/shlibs)
rm -rf pkg/debian

cp $D/control "$ROOT/DEBIAN/control"
printf 'Depends: %s\nInstalled-Size: %s\n' "$DEPENDS" "$(du -sk "$ROOT" | cut -f1)" >> "$ROOT/DEBIAN/control"
chmod 0755 "$ROOT/DEBIAN"

OUT="out/${KEBAB}_${VERSION}_${ARCH}.deb"
rm -f "$OUT"
dpkg-deb --build --root-owner-group "$ROOT" "$OUT" > /dev/null
echo "$OUT"
