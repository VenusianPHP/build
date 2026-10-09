# Agent guidelines — venusian/build

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/) (excluded from the Composer dist via `.gitattributes` `export-ignore`).

Before changing build code:

1. Read [`.okf/index.md`](.okf/index.md) first.
2. Open only the linked concepts the task needs.
3. When you learn something durable about **this package**, update the affected concept and append `.okf/log.md`. A concept an agent creates stays `status: draft` until a human verifies it.
4. Keep the bundle at the package root only.
5. Framework knowledge (packaged mode) belongs in `venusian/framework`'s bundle; installer knowledge (tool discovery, `install:sdk`) in `venusian/installer`'s.

## Package rules — 0.10.4

- Composer `venusian/build` **0.10.4**, type `venusian-tool`. PHP `^8.4|^8.5|^8.6`. No bin: the installer loads the classes named under `extra.venusian.commands` and adds them to `venusian`.
- The framework ships no build code. Everything that describes or packs an app lives here and runs from the app directory. Never add a `build:*` computer command to the framework.
- App facts live in `build.json` at the app root (`App\BuildJson`: every key defaulted, unknown keys refused by name, `schema/build.schema.json` beside it); machine facts (which docker context builds which target) in `~/.venusian/build/config.json` (`Hosts\UserConfig`). Never `config/build.php`; no secrets in either.
- The app's identity is `config/app.php` `app.id`. The build only checks it: a `build.json` `id` that differs throws, in these words, which stay: `build.json names {id} but config/app.php resolves app.id to {app_id}; make them match, the toolkit engines get pissy when app ids don't match.`
- `Build` packs the phar once and hands it to every `Targets\Target` in `build.targets` whose `available()` holds, reporting the rest. Anything that differs by platform lives behind `Target`, never in an `if` on the OS in `Build`.
- macOS (`MacTarget`): `phpacker/php-bin`'s NTS `micro.sfx`, `.so` files from the PHP running venusian or `build.json` `php` (its `extension_dir` when NTS, the NTS directory beside it when ZTS), this package's own phpmicro ini block (PHPacker's combiner collapses repeated `extension=` keys), a signed `.app`.
- Linux (`DebTarget`): a `.deb` compiled in the build image from `build-env/` (`recipe.sh`, `package.sh`), never a downloaded runtime. Core extension flags are `DebTarget::CORE`; every other name is `php-io-extensions/<name>` from Packagist through `Sources\Sources`, built from its `php-ext` `build-path` and configure flag, left out when its `os-families` leave Linux out. Docker only through `Hosts\Docker`, so tests record every command.
- Measured facts in [.okf/core/measured-facts.md](.okf/core/measured-facts.md); re-measure before contradicting one. Composer in the stage runs as `[PHP_BINARY, <composer on PATH>]` so its platform check sees that same PHP.
- Tests: Pest v4, temporary directories only, no network, no real `codesign`/`sips`/`iconutil` (recorded through the `$run` closure), no real docker (`Tests\Fakes\FakeDocker`), no real Packagist (`Tests\Fakes\FakeSources`); `composer install` runs only against locks that resolve offline. `Build` takes the host name, so every target is tested on any machine.
- Commits in this repository describe one change in the subject and one paragraph.
