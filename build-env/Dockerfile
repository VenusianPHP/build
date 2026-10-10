# The Linux build environment: Ubuntu 24.04 is the floor every .deb targets.
# Everything PHP and the catalog extensions link comes from apt, so a built
# app depends on the distribution's own libraries, except GLFW and SDL3 below.
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
    && rm -rf /var/lib/apt/lists/*
# GLFW 3.4 and SDL3 are newer than 24.04 ships (fact 22). Built here from pinned archives,
# static and position independent, into /usr/local, where pkg-config looks first; each .pc
# gets its private libraries in Libs, since the extensions ask pkg-config --libs, not --static.
# Both load their X11, Wayland and audio libraries at run time; the extensions declare those.
# GLFW 3.4 prefixes its Wayland protocol tables with _glfw_ but one, which SDL3 also defines;
# the rename gives it the prefix, so ext-glfw and ext-sdl3 link into one binary.
RUN apt-get update && apt-get install -y --no-install-recommends \
      cmake ninja-build nasm \
      libwayland-dev wayland-protocols libxkbcommon-dev libdecor-0-dev \
      libx11-dev libxext-dev libxrandr-dev libxinerama-dev libxcursor-dev libxi-dev libxfixes-dev libxss-dev libxtst-dev \
      libasound2-dev libpulse-dev libpipewire-0.3-dev libdbus-1-dev libudev-dev libdrm-dev libgbm-dev libegl-dev libgles-dev \
    && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL -o /tmp/glfw.zip https://github.com/glfw/glfw/releases/download/3.4/glfw-3.4.zip \
    && echo "b5ec004b2712fd08e8861dc271428f048775200a2df719ccf575143ba749a3e9  /tmp/glfw.zip" | sha256sum -c - \
    && unzip -q /tmp/glfw.zip -d /tmp \
    && cmake -S /tmp/glfw-3.4 -B /tmp/glfw-build -G Ninja -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local -DCMAKE_INSTALL_LIBDIR=lib \
         -DBUILD_SHARED_LIBS=OFF -DCMAKE_POSITION_INDEPENDENT_CODE=ON -DGLFW_BUILD_X11=ON -DGLFW_BUILD_WAYLAND=ON \
         -DGLFW_BUILD_EXAMPLES=OFF -DGLFW_BUILD_TESTS=OFF -DGLFW_BUILD_DOCS=OFF \
    && cmake --build /tmp/glfw-build \
    && cmake --install /tmp/glfw-build \
    && rm -rf /tmp/glfw.zip /tmp/glfw-3.4 /tmp/glfw-build \
    && objcopy --redefine-sym wp_fractional_scale_manager_v1_interface=_glfw_wp_fractional_scale_manager_v1_interface /usr/local/lib/libglfw3.a
RUN curl -fsSL -o /tmp/sdl3.tar.gz https://github.com/libsdl-org/SDL/releases/download/release-3.4.18/SDL3-3.4.18.tar.gz \
    && echo "9c75cf16330322c217dedd2e0609f1124f1b54b8633e763467b4684d0f4334a3  /tmp/sdl3.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/sdl3.tar.gz -C /tmp \
    && cmake -S /tmp/SDL3-3.4.18 -B /tmp/sdl3-build -G Ninja -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local -DCMAKE_INSTALL_LIBDIR=lib \
         -DSDL_SHARED=OFF -DSDL_STATIC=ON -DCMAKE_POSITION_INDEPENDENT_CODE=ON -DSDL_TESTS=OFF -DSDL_EXAMPLES=OFF -DSDL_INSTALL_TESTS=OFF \
    && cmake --build /tmp/sdl3-build \
    && cmake --install /tmp/sdl3-build \
    && rm -rf /tmp/sdl3.tar.gz /tmp/SDL3-3.4.18 /tmp/sdl3-build
# ext-vulkan's constants are generated from Vulkan headers 1.4.309; 24.04 ships 1.3.275. The
# headers go in /usr/local/include, which the compiler searches before /usr/include; the
# extension still links the distribution's loader, libvulkan1, which exports every core
# function it calls by name (later ones it looks up through vkGetDeviceProcAddr).
RUN curl -fsSL -o /tmp/vulkan-headers.tar.gz https://github.com/KhronosGroup/Vulkan-Headers/archive/refs/tags/v1.4.309.tar.gz \
    && echo "437925ada160d86ed763d29dcb9318c1bb0d024d7deaf77bc7c170b8eb6b6f10  /tmp/vulkan-headers.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/vulkan-headers.tar.gz -C /tmp \
    && cp -r /tmp/Vulkan-Headers-1.4.309/include/vulkan /tmp/Vulkan-Headers-1.4.309/include/vk_video /usr/local/include/ \
    && rm -rf /tmp/vulkan-headers.tar.gz /tmp/Vulkan-Headers-1.4.309
# Ubuntu's libjpeg is libjpeg.so.8 (package libjpeg8), Debian 13's libjpeg.so.62 (libjpeg62-turbo):
# a binary linked to either does not install on the other. libjpeg-turbo is built here static,
# with hidden symbols, so the binary exports no jpeg_* that could stand in for the libjpeg a
# system libtiff loads.
RUN curl -fsSL -o /tmp/libjpeg-turbo.tar.gz https://github.com/libjpeg-turbo/libjpeg-turbo/releases/download/3.2.0/libjpeg-turbo-3.2.0.tar.gz \
    && echo "6f30092cef9fb839779646608f4ee14ae3cbac989c47fa05e841b0841f09878e  /tmp/libjpeg-turbo.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/libjpeg-turbo.tar.gz -C /tmp \
    && cmake -S /tmp/libjpeg-turbo-3.2.0 -B /tmp/libjpeg-turbo-build -G Ninja -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local -DCMAKE_INSTALL_LIBDIR=lib \
         -DENABLE_SHARED=OFF -DENABLE_STATIC=ON -DCMAKE_POSITION_INDEPENDENT_CODE=ON -DCMAKE_C_VISIBILITY_PRESET=hidden \
         -DWITH_TURBOJPEG=OFF -DWITH_TOOLS=OFF -DWITH_TESTS=OFF \
    && cmake --build /tmp/libjpeg-turbo-build \
    && cmake --install /tmp/libjpeg-turbo-build \
    && rm -rf /tmp/libjpeg-turbo.tar.gz /tmp/libjpeg-turbo-3.2.0 /tmp/libjpeg-turbo-build
RUN for pc in /usr/local/lib/pkgconfig/glfw3.pc /usr/local/lib/pkgconfig/sdl3.pc; do \
      priv=$(sed -n 's/^Libs.private: //p' "$pc"); \
      sed -i "s|^Libs: \(.*\)|Libs: \1 $priv|" "$pc"; \
    done
WORKDIR /work
