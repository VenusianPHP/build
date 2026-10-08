# Venusian Build

`venusian build`, run inside a Venusian app, turns it into a native executable. macOS arm64 ships as a `.app` bundle in this release.

## Requirements

- macOS on Apple Silicon (`codesign`, `sips`, `iconutil` come with the system)
- [Composer](https://getcomposer.org/download/) on your `PATH`
- The app's native extensions installed for NTS PHP 8.4+ (`venusian install:ext`). The build takes the `.so` files from the PHP running `venusian`; when that PHP is ZTS (zhp), from the NTS directory beside its `extension_dir` (`…/20240924` beside `…/20240924-zts`)
- The [Venusian installer](https://github.com/VenusianPHP/installer)

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

The interview asks for the app name, bundle identifier, version, the sketch to run (only when there are several) and how to sign. Every answer defaults from `config/build.php`, so a second run is Enter all the way; `--no-interaction` takes the defaults outright. Output lands in `build/<Name>.app`, which the build also git-ignores.

```bash
venusian build "Night Sky"          # name argument over the manifest
venusian build --dir=../other-app   # another app
venusian build --no-interaction     # defaults, no terminal needed
```

Double-click the bundle, or run `build/<Name>.app/Contents/MacOS/<name>` from a terminal to see its output.

## config/build.php

Optional. Every key has a default.

```php
return [
    'name' => null,                   // null uses app.name
    'bundle_id' => null,              // null derives com.venusian.<kebab name>
    'version' => '0.1.0',
    'sketch' => null,                 // the only sketch, or asked when several
    'icon' => null,                   // PNG relative to the app, 1024x1024 preferred
    'extensions' => [],               // ext names beyond what composer.lock declares
    'php' => null,                    // NTS PHP binary supplying .so files; null = the PHP running venusian
    'repository' => 'phpacker/php-bin',
    'sign' => 'adhoc',                // or a codesign identity string
    'targets' => ['macos-arm64'],
    'env' => [],                      // over the app's .env in the packaged app's .env
    'env_except' => [],               // keys of the app's .env left out of the packaged app, e.g. ['DB_PASSWORD']
];
```

## What a build does

1. Checks the directory is a Venusian app (`composer.json` requires `venusian/framework`, `computer`, `bootstrap/app.php`).
2. Reads the manifest: `config/build.php` over the defaults, `app.name`, sketches under `app/Runner/Sketches`, every `ext-*` in `composer.lock`.
3. Packs `<name>.phar`: the app without `vendor`, `storage`, `tests`, caches and `.env`; `composer install --no-dev` in a stage; a `.env` of the app's `.env` with `build.env` over it and `build.env_except` left out (a packaged app has no other environment: `TOOLKIT_BRIDGE`, API keys and drivers come from here); stub runs `rocket <sketch>`.
4. Fetches a PHP micro runtime from the latest release of `phpacker/php-bin` for the app PHP's minor version, cached under `~/.venusian/build/runtimes`.
5. Finds a `.so` for every required extension the runtime lacks, in the extension directory of the PHP running `venusian` (its NTS twin when that PHP is ZTS), plus kqueue and pcurl when that PHP has them (the loop backend and HTTP on the loop).
6. Writes the executable: runtime + phpmicro ini block (`extension_dir=lib`, one `extension=` line each) + phar.
7. Lays out `<Name>.app` with a launcher, the binary, `lib/*.so`, `Info.plist`, the icon when configured.
8. Signs ad hoc, or with the configured identity under the hardened runtime.

The packaged app writes under `~/Library/Application Support/<Name>` (storage, database, bootstrap caches), seeded from the phar on first run. That is framework behaviour (`venusian/framework` packaged mode), not the build's.

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

Next: Linux arm64 (Raspberry Pi) and x86_64 targets on a glibc runtime, Windows, notarization in the build, HumanInput, DrawingSurface, TypePHP, Terran.

## Testing

```bash
composer install
vendor/bin/pest
```

Tests use temporary directories, a fake release client and recorded `codesign` calls; nothing reaches the network or signs anything.

## License

MIT.
