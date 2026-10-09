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
