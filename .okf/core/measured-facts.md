---
type: Concept
title: Measured facts
description: What was measured on 2026-10-08 on a Mac (arm64, macOS 15), a Pi 5 (Debian trixie), the gaming PC and the Jetson (Ubuntu 24.04) about phpmicro, php-bin, static-php-cli, PHPacker, signing, system-linked compiles and Linux desktops. The design rests on these; re-measure before contradicting one.
resource: docs/superpowers/specs/2026-10-08-venusian-build-design.md
tags: [build, phpmicro, php-bin, phpacker, codesign, measured]
status: draft
generated: { by: "claude-fable-5-1", at: "2026-10-08T00:00:00Z" }
sources:
  - id: spec
    resource: docs/superpowers/specs/2026-10-08-venusian-build-design.md
    title: design spec, section "Measured facts"
  - id: dist
    resource: docs/superpowers/specs/2026-10-08-venusian-distribution-design.md
    title: distribution design, section "Measured facts the design rests on"
---

Against `phpacker/php-bin` 0.4.0 (PHP 8.4.15 micro, NTS) and Homebrew-built extensions in `/opt/homebrew/lib/php/pecl/20240924`.[^spec]

1. A stock macOS `micro.sfx` loads shared extensions named in its embedded ini: kqueue, appkit, imgdec, opengl, metal all loaded in one process. An extension built against Homebrew PHP 8.4.25 NTS loads in micro 8.4.15 NTS; the API number (20240924) is what matters.
2. `dl()` does not exist in the micro SAPI.
3. PHPacker's `Combine::encodeINI` parses the ini into an associative array: repeated `extension=` keys collapse to the last. Hence `MicroCombiner` writes the block itself.
4. A relative `extension_dir` in the embedded ini resolves against the working directory, not the executable. Hence the launcher `cd`s.
5. The combined binary keeps the `adhoc, linker-signed` signature `micro.sfx` shipped with; the payload is outside the signed range and macOS runs it. The bundle is still signed as a whole.
6. php-bin Linux binaries are fully static musl and cannot `dlopen`. A glibc micro from static-php-cli 2.8.5 (`SPC_TARGET=native-native-gnu`, `--build-micro`, `--enable-zts` to match the Pi's PHP 8.4.20 ZTS) loads the Pi's own `.so` files: epoll, pcurl, gtk, qt, posi in one process (2026-10-08, Pi 5, Debian trixie, glibc 2.41). micro 8.4.26 loads extensions built for 8.4.20; API 20240924 is what matters, as in fact 1. First compile on the Pi took 23.5 minutes including the zig download; a cached runtime makes a build take seconds.
10. The glibc runtime links its libraries statically and exports their symbols. A `.so` that links the system copy of one of them carries versioned references (`curl_multi_socket_action@CURL_OPENSSL_4`), and those bind to the system `.so`, not the runtime's unversioned exports. pcurl 0.10.0 linked `libcurl.so.4`, so its socket action ran the system libcurl on a `CURLM*` that ext-curl made with the runtime's libcurl and segfaulted inside `libcurl.so.4`. pcurl 0.10.1 links no libcurl on any OS; its unversioned references bind to the runtime's copy, and packaged Stargazer fetched APOD over pcurl on the Pi. The same split would hit any extension that operates on another extension's library handles while linking its own copy of that library.
7. The `zenusian` alias runs the installer under `zhp`, PHP 8.4 ZTS; ZTS extensions cannot load into the NTS runtime. Homebrew installs the NTS twin's extensions in the same path without `-zts` (`/opt/homebrew/lib/php/pecl/20240924` beside `…/20240924-zts`), which `PhpFinder` derives. Shell aliases such as `php84` are invisible to a subprocess, so PATH search is not a source.
8. static-php-cli's registry plugin system exists only on its unreleased main branch (3.0); 2.8.5 is current. Nothing here depends on static-php-cli.
9. `PHP_SAPI` is `micro` in the runtime, and PHP serves `php://fd` under `cli` only; pcurl 0.10.1's `curlSocketStream` is how the framework's pcurl driver wraps curl's sockets there. `PHP_BINARY` is empty unless the ini sets `micro.php_binary`, and the binary runs its embedded payload whatever its arguments: the stub is what turns `<binary> <script inside the phar> …` into a worker run.

Facts 11 to 21, from the distribution design (2026-10-08 and 2026-10-09).[^dist]

11. **System-linked compile works.** In an `ubuntu:24.04` container on the gaming PC
    (Ryzen, 16 cores), PHP 8.4.26 ZTS with epoll, pcurl and gtk copied into `php-src/ext/` and
    enabled by their own `config.m4` flags configured and compiled in 33 s (image build 49 s).
    The binary links only Ubuntu's libraries. `dpkg-shlibdeps` produced
    `libc6 (>= 2.38), libcairo2, libcurl4t64, libglib2.0-0t64, libgtk-4-1 (>= 4.13.5), libonig5,
    libsqlite3-0, libssl3t64, libxml2, libzip4t64, zlib1g`. In a fresh `ubuntu:24.04` with only
    those packages installed: `PHP_SAPI` cli, all three extensions loaded, epoll woke on a socket
    pair, pcurl fetched HTTPS 200 through the system curl 8.5.0. Run on the PC host without
    libzip installed it failed on `libzip.so.4`: the Depends line is what makes it portable.
12. **A second libcurl crashes pcurl.** pcurl.so linked against `libcurl.so.4` carries versioned
    references (`curl_multi_socket_action@CURL_OPENSSL_4`) that bind to the system library even
    inside a runtime that carries its own static libcurl; ext-curl's handles come from the other
    copy and the first socket action segfaults. pcurl `e9df4ce` links no libcurl on any OS.
    System-linked mode cannot hit this: one libcurl per process.
13. **The GTK compile-in failure was a header mix.** zig's bundled glibc headers plus
    `-I/usr/include/aarch64-linux-gnu` from GTK's pkg-config flags gave `unknown type name
    '__intmax_t'`. The distro compiler with the distro headers (fact 11) has no second header set.
14. **The build machine sets the floor for everything it links.** gtk.so built on the Pi's
    Debian 13 (GTK 4.18) demands `libgtk-4-1 (>= 4.15.4)` and will not install on Ubuntu 24.04
    (GTK 4.14.5). Build inside the floor release, never on the host.
15. **GTK 4 by release** (packages.ubuntu.com, packages.debian.org): Ubuntu 22.04 4.6.9,
    Ubuntu 24.04 4.14.2, Debian 12 4.8.3, Debian 13 4.18.6. ext-gtk requires 4.12. Floor: Ubuntu
    24.04 (glibc 2.39, GTK 4.14).
16. **Machines.** Jetson Orin Nano: JetPack 7.2 = Ubuntu 24.04.4, aarch64, glibc 2.39, GTK 4.14.5,
    docker, PHP 8.4.8 ZTS. Gaming PC: Ubuntu 24.04.4, x86_64, glibc 2.39, GTK 4.14.5, docker.
    Pi 5: Debian 13, aarch64, glibc 2.41, GTK 4.18.6, no docker. Arduino UNO Q (specs): Debian 13,
    aarch64. VENTUNO Q (specs): aarch64, 16 GB, OS release unannounced. Luckfox Pico Mini (specs):
    Buildroot/BusyBox, uClibc, 32-bit Cortex-A7, 64 MB; its RISC-V core is a companion MCU, not
    the Linux CPU.
17. **`PHP_SAPI === 'cli'` is how libraries detect a terminal process.** In Stargazer's
    dependencies: symfony/var-dumper (HTML dumps otherwise, seen in the packaged app's log),
    error-handler, process, monolog, guzzle, whoops, collision. The framework's packaged mode
    keys off the phar base path, not the SAPI name.
18. **Linux desktop identity is shared today.** `jovian/venusian-gtk` `GTKSession` and
    `jovian/venusian-qt` `QtSession` default the application ID / desktop file name to
    `org.venusian.Surface`; no config in surface, jovian or Stargazer overrides it. Docks match
    a window to its `.desktop` file by that ID, so every Venusian app shows one generic entry.
19. **Vendor weight.** Stargazer production vendor: 69 packages, 39.5 MB, of which 21.1 MB (54%)
    is tests, docs and non-PHP files; surface 12.8 MB and framework 10.3 MB lead because path
    repositories copy everything `export-ignore` would drop.
20. **Debian tooling needs no manual dependency list.** `dpkg-shlibdeps` reads the shipped
    binaries and emits Depends with version floors (facts 11, 14).
21. The SAPI binary realpaths its own location (PHP's `executable_location` handling), so a `.app` or `.deb` launcher may be a symlink and `PHP_BINARY` is still the real file; `mktemp` paths on macOS resolve through `/private`.

[^spec]: design spec, section "Measured facts"
[^dist]: distribution design, section "Measured facts the design rests on"
