---
okf_version: "0.2"
status: draft
generated: { by: "claude-fable-5-1", at: "2026-10-08T00:00:00Z" }
---

# venusian/build Knowledge Bundle

Package knowledge for `venusian/build` 0.10.0: the `venusian build` tool that compiles a Venusian app into a native executable. Read this index first; open only the concepts needed.

**Trust rule:** concepts an agent creates stay `draft` until a human verifies them. Behavioural claims name the Pest test that backs them.
**Scope:** this package only. Packaged-mode paths live in `venusian/framework`'s bundle; tool discovery and `install:sdk` in `venusian/installer`'s.

# Orientation

* [Package](orientation/package.md) - Composer identity, `venusian-tool` type, how the installer reveals `build`. (`draft`)

# Core

* [Build pipeline](core/build-pipeline.md) - Inspect → manifest → interview → phar → runtime → extensions → combine → bundle → sign; the class behind each step. (`draft`)
* [Measured facts](core/measured-facts.md) - What was measured on 2026-10-08 about phpmicro, php-bin, PHPacker and signing; the design rests on these. (`draft`)
