# Directory Update Log

## 2026-10-08

* **Creation**: first bundle for `venusian/build` 0.10.0 — [Package](orientation/package.md), [Build pipeline](core/build-pipeline.md), [Measured facts](core/measured-facts.md). All `status: draft`. The framework carries no build code; `venusian build` packs the app from its own directory.

* **Packaged .env**: the app's `.env` ships by default with `build.env` over it, less `build.env_except`; an app without `config/build.php` no longer starts with no toolkit.

* **Pool workers in a packaged app**: the ini sets `micro.php_binary=./<kebab>-bin` and the stub runs a script inside the phar when named as the first argument, so the framework's process pools spawn the app binary as their worker. Measured fact 9 records the SAPI, `php://fd` and `PHP_BINARY` facts behind this and pcurl 0.10.1.

* **Linux targets**: `linux-arm64` and `linux-x86_64` beside `macos-arm64`, each a `Target`. A build makes the host's target; `build.targets` defaults to the host. Linux runtimes are compiled by static-php-cli (`SpcRuntimes`) as glibc to the PHP's thread safety; Linux apps are `build/<Name>/` with a symlink-safe launcher, unsigned. Measured facts 6 and 10 record the Pi results, including the second-libcurl segfault that pcurl 0.10.1 fixes by linking no libcurl.

## 2026-10-09

* **build.json**: `config/build.php` is gone; `build.json` at the app root carries the build facts with every key defaulted and unknown keys refused, `schema/build.schema.json` beside it. The app's identity is `config/app.php` `app.id`; a `build.json` `id` that differs stops the build. [Build pipeline](core/build-pipeline.md) steps 2 and 3.

* **Interview write-back**: author and summary asked while empty, the id never; answers written back to `build.json`.

* **Targets take the phar**: `Build` packs once and builds every available target named; `LinuxTarget`, `LinuxBundle` and the static-php-cli runtime are removed.

* **Linux as .deb**: `DebTarget` compiles PHP 8.4.26 with the Venusian SAPI and the extensions inside the Ubuntu 24.04 build image (`build-env/`) on a docker context, from sources `Sources` fetches (php.net, the SAPI tag, Packagist), and packages a `.deb` with generated Depends and, for a windowed app, desktop entry, icon and AppStream file. Hosts come from `~/.venusian/build/config.json`. Measured facts 11 to 21 moved here from the distribution design.

* **Extension releases**: extensions come from the 0.10 line (`0.10.x-dev`, else the newest 0.10 tag) keyed by commit, so a moved branch recompiles once; each declares its apt and Homebrew packages under `extra.venusian.system`, which the recipe installs and the `.deb` depends on or recommends. The image builds GLFW 3.4, SDL3 and libjpeg-turbo static and carries Vulkan headers 1.4.309, so one `.deb` per CPU installs on Ubuntu 24.04 and Debian 13. ext-qt links Qt when compiled in. A declared run-time package joins Depends once. ext-qt builds on Qt 6.4; jovian's GTK session sets the program name to the app id for X11 `WM_CLASS`. [Build pipeline](core/build-pipeline.md)

* **macOS path**: a native compile at macOS 14 against a pinned static library set (`build-env/macos/`), the binary and phar in a `.app` with `Frameworks` for the Vulkan loader and MoltenVK, a strict hardened-runtime signature, a `.dmg`, and notarization with a Developer ID and a notarytool profile from the per-user config. The micro path (php-bin, host `.so` files, appended phar) is removed; `ExtensionSet` resolves the set for both OSes. [Build pipeline](core/build-pipeline.md) step 6.

## 2026-10-10

* **Leaner builds**: the phar ships what git would publish, for the app and each path-repository package, with an authoritative classmap; a sqlite app ships a fresh migrated `database/database.sqlite`; a scan over nikic/php-parser adds the extensions the app's own code calls and reports vendor-only ones; every target runs the built binary once on `.venusian-boot-check.php` before packaging. Stargazer: phar 26 MB from 46, `.dmg` 13 MB from 30, `.deb` 6.6 MB from 17.1. [Build pipeline](core/build-pipeline.md) steps 5, 5a, 6 and 9; [Measured facts](core/measured-facts.md) fact 25.

* **Any php-ext package, and the invoking PHP**: `build.json` `extensions` names any php-ext package on Packagist in full (`phpredis/phpredis`, `pecl/parallel`), compiled under its declared extension name at its newest stable tag; an unknown plain name lists Packagist's php-ext hits. The runtime follows the PHP running the build again (thread safety, newest release of its minor; `zts` only overrides), as Angel asked on 2026-10-08; build.json's `zts: false` default had dropped that. [Build pipeline](core/build-pipeline.md) step 7.
