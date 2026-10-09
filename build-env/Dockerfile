# The Linux build environment: Ubuntu 24.04 is the floor every .deb targets.
# Everything PHP and the catalog extensions link comes from apt, so a built
# app depends on the distribution's own libraries and nothing travels with it.
# venusian build tags the image with this file's sha1 (first 12 hex), so a change
# here is a new image on every host; the workflow pushes the same tag.
FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
      build-essential autoconf pkg-config ca-certificates curl xz-utils unzip file dpkg-dev fakeroot \
      libxml2-dev libsqlite3-dev libcurl4-openssl-dev libssl-dev zlib1g-dev libonig-dev libzip-dev libgmp-dev libicu-dev \
      libpq-dev libmysqlclient-dev \
      libbz2-dev libffi-dev libsodium-dev libxslt1-dev libedit-dev libtidy-dev libldap-dev libfreetype-dev libwebp-dev \
      imagemagick \
      libgtk-4-dev \
      qt6-base-dev qt6-multimedia-dev \
      libftdi1-dev libusb-1.0-0-dev \
      libjpeg-dev libpng-dev libtiff-dev \
      libglfw3-dev \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /work
