# Venusian Build

`venusian build`, run inside a Venusian app, turns it into something another machine installs and runs: a signed `.app` in a `.dmg` for Apple Silicon Macs on macOS 14 and newer, and a `.deb` for Linux arm64 and x86_64 (Ubuntu 24.04 and newer, Debian 13, Raspberry Pi OS on trixie, JetPack 7).

## Requirements

Everywhere:

- [Composer](https://getcomposer.org/download/) on your `PATH`
- The [Venusian installer](https://github.com/VenusianPHP/installer)
- The app's `config/app.php` has `'id' => env('APP_ID', 'com.venusian.app')` under `name` (framework 0.10 skeletons ship it)

macOS targets, built on an Apple Silicon Mac:

- The Xcode command line tools (`xcode-select --install`): clang, `codesign`, `hdiutil`, `notarytool`
- `cmake`, `autoconf` and `pkg-config` (`brew install cmake autoconf pkg-config`): build tools only; nothing from Homebrew ships in an app
- A macOS app's toolkit is AppKit (`jovian/venusian-appkit`). GTK and Qt run on a Mac from source for testing; a macOS build leaves them out, and refuses an app that has no AppKit

Linux targets, built from any machine:

- Docker on a machine of the target's CPU: the one you run `venusian build` on, or another reached through a [docker context](https://docs.docker.com/engine/manage-resources/contexts/) over ssh. A Mac with Docker Desktop builds `linux-arm64` in its VM
- Nothing else: PHP, the SAPI and the extensions compile inside the build image

```bash
docker context create gamingpc --docker "host=ssh://angel@192.168.4.38"   # an x86_64 box
docker context create jetson --docker "host=ssh://angel@192.168.4.40"     # an arm64 box
```

The context needs ssh key auth that works without a prompt (a key in the agent or the macOS keychain). Then name which context builds which target in `~/.venusian/build/config.json`, a file about your machines, never committed with an app:

```json
{"hosts": {"linux-x86_64": "gamingpc", "linux-arm64": "jetson"}}
```

A target left out builds on the current docker context when its CPU matches.

## Installation

```bash
venusian install:sdk
```

That runs `composer global require venusian/build`. The installer finds the package by its Composer type, `venusian-tool`, and `venusian build` appears.

## Usage

```bash
cd my-app
venusian build
```

The interview asks for the app name, version, the sketch to run (only when there are several), the author and one-line summary while they are empty, and, once per machine when a macOS target is built, how macOS builds sign (saved to `~/.venusian/build/config.json`). Every answer defaults from `build.json` and is written back to it, so a second run is Enter all the way; `--no-interaction` takes the defaults outright. Output lands in `build/`; the build git-ignores it.

```bash
venusian build "Night Sky"          # name argument over the manifest
venusian build --dir=../other-app   # another app
venusian build --no-interaction     # defaults, no terminal needed
```

On macOS, open `build/<kebab>-<version>-macos-arm64.dmg` and drag the app to Applications, or run `build/<Name>.app/Contents/MacOS/<kebab>` from a terminal to see its output. Started from Finder, the app's output goes to `~/Library/Logs/<kebab>/<kebab>.log`. On Linux:

```bash
sudo apt install ./build/<kebab>_<version>_<arch>.deb
<kebab>
```

apt installs the libraries the `.deb` depends on; a windowed app appears in the desktop's app grid under its name and icon.

## build.json

Optional, at the app root, committed with the app. Every key has a default; an unknown key is refused by name. Editors validate it against [`schema/build.schema.json`](schema/build.schema.json) through the `$schema` line the build writes.

```json
{
    "$schema": "https://raw.githubusercontent.com/VenusianPHP/build/main/schema/build.schema.json",
    "id": null,
    "name": null,
    "version": "0.1.0",
    "build": 1,
    "sketch": null,
    "icon": null,
    "summary": "",
    "description": "",
    "author": "",
    "homepage": "",
    "license": "",
    "category": "Utility",
    "permissions": {},
    "extensions": [],
    "zts": false,
    "targets": [],
    "env": {},
    "env_except": []
}
```

| Key | Meaning |
|---|---|
| `id` | Reverse-DNS identity. The app's identity is `config/app.php` `app.id` (`APP_ID`); `build.json` may repeat it and must then match |
| `name` | Display name; `null` uses `app.name` |
| `version` | Starts with a digit: Debian takes it as the package version |
| `sketch` | The sketch the packaged app runs; the only one, or asked when several |
| `icon` | Square PNG relative to the app, at least 16 px; 1024 px serves both: the `.deb` gets every hicolor size from 16 to 512 the source covers |
| `summary`, `description` | One line, and paragraphs separated by blank lines: the package description and the AppStream entry |
| `author` | `Name <email>`, the `.deb` maintainer; required for Linux targets |
| `homepage`, `license` | Homepage URL, SPDX identifier |
| `category` | freedesktop main category: AudioVideo, Development, Education, Game, Graphics, Network, Office, Science, Settings, System, Utility; the `.app` maps it to an App Store category |
| `extensions` | Extension names beyond the `ext-*` that `composer.lock` declares |
| `zts` | Thread-safe PHP (Linux and macOS) |
| `build` | Whole number, one more each release: the `.app`'s `CFBundleVersion` |
| `permissions` | macOS: `camera`, `microphone`, `bluetooth`, each with the sentence macOS shows when it asks; the `.app` gets the usage string and the hardened-runtime device entitlement |
| `targets` | From `macos-arm64`, `linux-arm64`, `linux-x86_64`; `[]` is the machine running the build |
| `env`, `env_except` | Over the app's `.env` in the packaged app, and `.env` keys left out (`DB_PASSWORD`) |

When `build.json` and `app.id` disagree the build stops: `build.json names {id} but config/app.php resolves app.id to {app_id}; make them match, the toolkit engines get pissy when app ids don't match.`

## What a build does

1. Checks the directory is a Venusian app (`composer.json` requires `venusian/framework`, `computer`, `bootstrap/app.php`).
2. Reads the manifest: `build.json` over the defaults, `app.name` and `app.id`, sketches under `app/Runner/Sketches`, every `ext-*` in `composer.lock` (the packages' and the app's own), the default database connection; a `jovian/*` toolkit package in the lock makes the app windowed.
3. Packs `<kebab>.phar` once: the app as git would publish it (tracked and untracked files `.gitignore` leaves in, less `export-ignore` in `.gitattributes`), without `vendor`, `storage`, `tests`, caches and `.env`; `composer install --no-dev` in a stage, each path-repository package replaced by what its own repository publishes, then an authoritative classmap; a `.env` of the app's `.env` with `build.env` over it, `build.env_except` left out and `APP_ID` always set (a packaged app has no other environment); the stub runs `rocket <sketch>`, or, named a script inside the phar as its first argument, that script (how the framework's process pools get workers).
   A packaged app's database is sqlite for now; another default connection stops the build. A sqlite app whose connection names `database/database.sqlite` ships a fresh one, migrated from `database/migrations` as the build runs; the developer's own file never ships. On first run it is copied to the app's data directory, where it is writable; a `DB_DATABASE` in the app's `.env` names the developer's machine and is left out of the packaged one.
4. Scans the packed code for calls into PHP extensions (`resources/extension-symbols.json`, regenerated with `bin/extension-symbols`). A call in the app's own code adds its extension and says so: `Adding intl: app/Money.php uses NumberFormatter`; a sqlite default connection adds pdo_sqlite. Calls in `vendor` only are reported, `Vendor code also calls, not compiled in: …`, since a package's guarded call is not a need; add one to `build.json` `extensions` when the app needs it.
5. Hands the phar to every target in `build.targets` this machine can build, reporting the others with the reason. Each target runs the built binary once, with HOME in a temporary directory, on the packed `.venusian-boot-check.php`: it boots the app, builds its sketch and opens its database. An app that fails there stops the build with what it said, instead of on a user's first launch.

macOS (`macos-arm64`, on an Apple Silicon Mac):

- The library set, once per set of pins: `build-env/macos/libs.sh` builds OpenSSL, OpenLDAP's client libraries, Oniguruma, libpng, libjpeg-turbo, libtiff, GLFW, SDL3, libusb and libftdi static, and the Vulkan loader with MoltenVK as dylibs, at the macOS 14 floor, into `~/.venusian/build/macos/prefix-14.0-<hash>`. Libraries the macOS SDK carries (zlib, libxml2, sqlite3, curl, iconv, bzip2, libffi, libedit, libxslt) are the system's; the system's LDAP is too old for PHP 8.4's ldap, so OpenLDAP is built.
- `build-env/macos/recipe.sh` compiles php-src 8.4.26 with the Venusian SAPI and the extensions compiled in (ctype, filter, mbstring, openssl, pdo, kqueue, pcurl, rasterize and what they need, plus the app's) for macOS 14, and refuses a binary that links anything but the OS and the bundle's Frameworks. The binary is kept per app under `~/.venusian/build/macos/runtimes/<kebab>/<set hash>/` while the set stays the same. gettext, gmp, intl, pdo_pgsql, pgsql, sodium, tidy and zip have no library on macOS and stop the build by name.
- `<Name>.app`: the binary as `Contents/MacOS/<kebab>`, the phar in `Contents/Resources`, the icon, `Info.plist` (id, name, version, build, category, permissions, macOS 14), and when the app uses Vulkan the loader and MoltenVK in `Contents/Frameworks` with MoltenVK's driver manifest in `Contents/Resources/vulkan/icd.d`. Signed under the hardened runtime and checked with `codesign --verify --strict --deep`.
- `<kebab>-<version>-macos-arm64.dmg`: the app beside an Applications link; with a Developer ID it is signed, notarized, stapled and assessed by Gatekeeper.

Linux (`linux-arm64`, `linux-x86_64`, on a docker context of that CPU):

- The build image `ghcr.io/venusianphp/build-env:ubuntu24.04-<sha1 of the Dockerfile, 12 hex>` is pulled, or built once per host from [`build-env/`](build-env/) when the registry has none; a changed Dockerfile is a new image everywhere.
- php-src 8.4.26, the [Venusian SAPI](https://github.com/VenusianPHP/sapi) v0.10.2 and each extension's `php-io-extensions/<name>` on the 0.10 line (its `0.10.x-dev` branch, else its newest 0.10 tag) from Packagist are fetched once into `~/.venusian/build/sources`, an extension's archive per commit. Each extension declares its system packages under `extra.venusian.system` in its composer.json. An extension whose `php-ext` metadata leaves Linux out (appkit, kqueue) is left out and reported.
- Sources, the phar and the recipe go into a docker volume `venusian-build-<kebab>` on the host. `recipe.sh` compiles PHP with the SAPI and the extensions compiled in (ctype, filter, mbstring, openssl, pdo, epoll, pcurl, rasterize and what they need, plus the app's), linked to Ubuntu's libraries, except GLFW 3.4 and SDL3 (24.04 ships GLFW 3.3 and no SDL3) and libjpeg-turbo (Ubuntu's `libjpeg8` is not in Debian 13), which the image builds static, and the Vulkan 1.4.309 headers ext-vulkan compiles against; declared apt build packages the image lacks are installed first (and again in the packaging container). The binary carries no RPATH. A volume keeps the compiled runtime while the extension set and every extension's commit stay the same. PHP's own extensions compile in by flag; dba, enchant, odbc, opcache, pdo_dblib, pdo_firebird, pdo_odbc and snmp are not compiled in yet and stop the build by name.
- `package.sh` lays out `/usr/lib/<kebab>/<kebab>` and `<kebab>.phar` beside it, `/usr/bin/<kebab>`, and for a windowed app `/usr/share/applications/<id>.desktop`, the icon rendered to each `hicolor` size and `/usr/share/metainfo/<id>.metainfo.xml`; `dpkg-shlibdeps` writes Depends from what the binary links (a `t64` package also accepts its plain name, as Debian 13 ships some), the extensions' declared run-time packages join it, and their recommended ones become Recommends; `dpkg-deb` builds `<kebab>_<version>_<arch>.deb`, which comes back into `build/`.

A first Linux build on a host builds the image, which compiles GLFW, SDL3 and libjpeg-turbo, then compiles PHP: a few minutes. A new extension set recompiles: about a minute on a Ryzen gaming PC, about five on a Jetson Orin Nano. A build of the same set repackages in about half a minute per host.

The packaged app writes under `~/Library/Application Support/<Name>` on macOS and `$XDG_DATA_HOME/<Name>` (else `~/.local/share/<Name>`) on Linux: storage, database, bootstrap caches, seeded from the phar on first run. That is framework behaviour (`venusian/framework` packaged mode), not the build's.

## Signing

How macOS builds sign is a fact about your machine, kept in `~/.venusian/build/config.json`; the first interactive build asks once:

```json
{"macos": {"sign": "Developer ID Application: Your Name (TEAMID)", "notary_profile": "venusian"}}
```

- `"sign": "adhoc"` (the default) opens on the Mac that built it; the build says the `.dmg` is not notarized.
- A Developer ID identity from your keychain signs the frameworks, the app and the `.dmg` with a secure timestamp under the hardened runtime, with JIT allowed for PCRE and the device entitlement of each declared permission. The build looks the name up before compiling and signs by the certificate's SHA-1; when a renewed certificate and the one it replaces share the name, the one that expires last signs. A SHA-1 works as `sign` too.
- `notary_profile` names a notarytool keychain profile (`xcrun notarytool store-credentials venusian`); with it the build submits the `.dmg`, waits for Apple, staples the ticket and checks it as Gatekeeper checks a download. A rejection prints Apple's issues.

## The ecosystem

| Package | Role |
|---|---|
| `venusian/framework` | The runtime: event loop, IOPools, sketches, workflows, queue, database, console |
| `venusian/surface` and `venusian-surface/*` | Windows, drawing engines, toolkits, embedded displays |
| `scrapyard-io/*` | Hardware: GPIO, I2C, SPI, UART, PWM, chip adapters |
| `jovian/*` | Toolkit and engine drivers (AppKit, GTK, Qt, SDL3, OpenGL, Metal, Vulkan) |
| `php-io-extensions/*` | The C extensions those drivers bind |
| `venusian/installer` | `venusian new`, `venusian install:ext`, `venusian install:sdk` |
| `venusian/build` | This package: `venusian build` |

Next: Windows, HumanInput, DrawingSurface, TypePHP, Terran.

## Testing

```bash
composer install
vendor/bin/pest
```

Tests use temporary directories, a fake Mac (SDK path, library set, recipe, `otool`, `codesign`, `hdiutil`, `notarytool`), a fake Packagist and a fake docker CLI; nothing reaches the network, compiles, runs docker or signs anything. Every target is tested on any machine: the host is passed in.

## License

MIT.
