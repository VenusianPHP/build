---
okf_version: "0.2"
status: draft
generated: { by: "claude-fable-5-1", at: "2026-10-08T00:00:00Z" }
---

# venusian/build Knowledge Bundle

Package knowledge for `venusian/build` 0.10.5: the `venusian build` tool that compiles a Venusian app into a signed `.app` in a `.dmg` for macOS 14+ and a `.deb` for Linux. Read this index first; open only the concepts needed.

**Trust rule:** concepts an agent creates stay `draft` until a human verifies them. Behavioural claims name the Pest test that backs them.
**Scope:** this package only. Packaged-mode paths live in `venusian/framework`'s bundle; tool discovery and `install:sdk` in `venusian/installer`'s.

# Orientation

* [Package](orientation/package.md) - Composer identity, `venusian-tool` type, how the installer reveals `build`. (`draft`)

# Core

* [Build pipeline](core/build-pipeline.md) - Inspect → build.json → manifest and app.id → interview → phar once → each target: `.app` (library set, native compile, bundle, sign, `.dmg`, notarize) or `.deb` (resolve, docker host, compile in the build image, package). (`draft`)
* [Measured facts](core/measured-facts.md) - What was measured on 2026-10-08 and 09 about phpmicro, php-bin, PHPacker, signing, system-linked compiles, GTK floors and Linux desktop identity; the design rests on these. (`draft`)
