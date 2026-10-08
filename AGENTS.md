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
- The runtime is `phpacker/php-bin`'s `micro.sfx`; this package writes the phpmicro ini block itself (PHPacker's combiner collapses repeated `extension=` keys). Measured facts in [.okf/core/measured-facts.md](.okf/core/measured-facts.md); re-measure before contradicting one.
- `.so` files come from the PHP running venusian: its `extension_dir` when NTS, the NTS directory beside it when ZTS; `build.php` overrides. Composer in the stage runs as `[PHP_BINARY, <composer on PATH>]` so its platform check sees that same PHP.
- Tests: Pest v4, temporary directories only, no network, no real `codesign`/`sips`/`iconutil` (recorded through the `$run` closure), `composer install` runs only against locks that resolve offline.
- Commits in this repository describe one change in the subject and one paragraph.
