# Venusian Build

`venusian build`, run inside a Venusian app, turns it into something another machine installs and runs: a signed `.app` bundle for macOS arm64, and a `.deb` for Linux arm64 and x86_64 (Ubuntu 24.04 and newer, Debian 13, Raspberry Pi OS on trixie, JetPack 7).

## Requirements

Everywhere:

- [Composer](https://getcomposer.org/download/) on your `PATH`
- The [Venusian installer](https://github.com/VenusianPHP/installer)
- The app's `config/app.php` has `'id' => env('APP_ID', 'com.venusian.app')` under `name` (framework 0.10 skeletons ship it)

macOS (Apple Silicon):

- `codesign`, `sips`, `iconutil`, which come with the system
- The app's native extensions installed for PHP 8.4+ (`venusian install:ext`), NTS: the runtime is NTS. When the PHP running `venusian` is ZTS (zhp), the build takes the NTS directory beside its `extension_dir` (`…/20240924` beside `…/20240924-zts`)

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

The interview asks for the app name, version, the sketch to run (only when there are several), the author and one-line summary while they are empty, and, when a macOS target is built, how to sign. Every answer defaults from `build.json` and is written back to it, so a second run is Enter all the way; `--no-interaction` takes the defaults outright. Output lands in `build/`; the build git-ignores it.

```bash
venusian build "Night Sky"          # name argument over the manifest
venusian build --dir=../other-app   # another app
venusian build --no-interaction     # defaults, no terminal needed
```

On macOS, double-click `build/<Name>.app`, or run `build/<Name>.app/Contents/MacOS/<kebab>` from a terminal to see its output. On Linux:

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
    "sketch": null,
    "icon": null,
    "summary": "",
    "description": "",
    "author": "",
    "homepage": "",
    "license": "",
    "category": "Utility",
    "extensions": [],
    "zts": false,
    "php": null,
    "repository": "phpacker/php-bin",
    "sign": "adhoc",
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
| `category` | freedesktop main category: AudioVideo, Development, Education, Game, Graphics, Network, Office, Science, Settings, System, Utility |
| `extensions` | Extension names beyond the `ext-*` that `composer.lock` declares |
| `zts` | Thread-safe PHP on Linux |
| `php`, `repository`, `sign` | macOS: the NTS PHP supplying `.so` files, the php-bin release repository, `adhoc` or a codesign identity |
| `targets` | From `macos-arm64`, `linux-arm64`, `linux-x86_64`; `[]` is the machine running the build |
| `env`, `env_except` | Over the app's `.env` in the packaged app, and `.env` keys left out (`DB_PASSWORD`) |

When `build.json` and `app.id` disagree the build stops: `build.json names {id} but config/app.php resolves app.id to {app_id}; make them match, the toolkit engines get pissy when app ids don't match.`

## What a build does

1. Checks the directory is a Venusian app (`composer.json` requires `venusian/framework`, `computer`, `bootstrap/app.php`).
2. Reads the manifest: `build.json` over the defaults, `app.name` and `app.id`, sketches under `app/Runner/Sketches`, every `ext-*` in `composer.lock`; a `jovian/*` toolkit package in the lock makes the app windowed.
3. Packs `<kebab>.phar` once: the app without `vendor`, `storage`, `tests`, caches and `.env`; `composer install --no-dev` in a stage; a `.env` of the app's `.env` with `build.env` over it, `build.env_except` left out and `APP_ID` always set (a packaged app has no other environment); the stub runs `rocket <sketch>`, or, named a script inside the phar as its first argument, that script (how the framework's process pools get workers).
4. Hands the phar to every target in `build.targets` this machine can build, reporting the others with the reason.

macOS (`macos-arm64`, on a Mac): php-bin's NTS `micro.sfx`, the `.so` files of the app's extensions plus kqueue and pcurl when present, one executable, `<Name>.app` with `Info.plist` and the icon, signed ad hoc or with the identity under the hardened runtime.

Linux (`linux-arm64`, `linux-x86_64`, on a docker context of that CPU):

- The build image `ghcr.io/venusianphp/build-env:ubuntu24.04-<sha1 of the Dockerfile, 12 hex>` is pulled, or built once per host from [`build-env/`](build-env/) when the registry has none; a changed Dockerfile is a new image everywhere.
- php-src 8.4.26, the [Venusian SAPI](https://github.com/VenusianPHP/sapi) v0.10.1 and each extension's latest tagged `php-io-extensions/<name>` release on Packagist are fetched once into `~/.venusian/build/sources`. An extension whose `php-ext` metadata leaves Linux out (appkit, kqueue) is left out and reported.
- Sources, the phar and the recipe go into a docker volume `venusian-build-<kebab>` on the host. `recipe.sh` compiles PHP with the SAPI and the extensions compiled in (ctype, filter, mbstring, openssl, pdo, epoll, pcurl and what they need, plus the app's), linked to Ubuntu's libraries; a volume keeps the compiled runtime while the extension set is the same. PHP's own extensions compile in by flag; dba, enchant, odbc, opcache, pdo_dblib, pdo_firebird, pdo_odbc and snmp are not compiled in yet and stop the build by name.
- `package.sh` lays out `/usr/lib/<kebab>/<kebab>` and `<kebab>.phar` beside it, `/usr/bin/<kebab>`, and for a windowed app `/usr/share/applications/<id>.desktop`, the icon rendered to each `hicolor` size and `/usr/share/metainfo/<id>.metainfo.xml`; `dpkg-shlibdeps` writes Depends from what the binary links; `dpkg-deb` builds `<kebab>_<version>_<arch>.deb`, which comes back into `build/`.

A first Linux build on a host builds the image and compiles PHP: under two minutes on a Ryzen gaming PC, about six on a Jetson Orin Nano. A new extension set recompiles (under a minute on the Ryzen); a build of the same set repackages in about twenty seconds.

The packaged app writes under `~/Library/Application Support/<Name>` on macOS and `$XDG_DATA_HOME/<Name>` (else `~/.local/share/<Name>`) on Linux: storage, database, bootstrap caches, seeded from the phar on first run. That is framework behaviour (`venusian/framework` packaged mode), not the build's.

## Signing

`adhoc` runs on the Mac that built it. A Developer ID identity signs with the hardened runtime and the entitlements a PHP runtime needs (JIT, unsigned executable memory, library validation off for the bundled `.so` files). Notarization is yours to run on the bundle afterwards.

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

Next: Windows, notarization in the build, HumanInput, DrawingSurface, TypePHP, Terran.

## Testing

```bash
composer install
vendor/bin/pest
```

Tests use temporary directories, a fake php-bin release, a fake Packagist, a fake docker CLI and recorded `codesign` calls; nothing reaches the network, compiles, runs docker or signs anything. Every target is tested on any machine: the host is passed in.

## License

MIT.
