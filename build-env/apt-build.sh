#!/bin/sh
# Installs the declared apt build packages (/work/in/apt.build, one per line) the image lacks.
# recipe.sh and package.sh each run in their own container, so both call it: the compile
# needs the headers, dpkg-shlibdeps the libraries those packages bring.
set -eu
MISSING=""
for P in $(cat /work/in/apt.build); do
    dpkg -s "$P" > /dev/null 2>&1 || MISSING="$MISSING $P"
done
if [ -n "$MISSING" ]; then
    apt-get update -qq > /dev/null
    # shellcheck disable=SC2086
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends $MISSING > /dev/null
fi
