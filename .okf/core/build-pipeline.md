---
type: Concept
title: Build pipeline
description: The steps of `venusian build` in order and the class behind each.
resource: src/Build.php
tags: [build, pipeline, phar, phpmicro, codesign]
status: draft
generated: { by: "claude-fable-5-1", at: "2026-10-08T00:00:00Z" }
sources:
  - id: command
    resource: src/Console/BuildCommand.php
    title: BuildCommand
  - id: build
    resource: src/Build.php
    title: Build::run()
  - id: inspector
    resource: src/App/AppInspector.php
    title: AppInspector
  - id: reader
    resource: src/App/ManifestReader.php
    title: ManifestReader and read.php
  - id: interview
    resource: src/Console/Interview.php
    title: Interview
  - id: phar
    resource: src/Phar/PharBuilder.php
    title: PharBuilder and pack.php
  - id: runtime
    resource: src/Runtime/RuntimeStore.php
    title: RuntimeStore
  - id: ext
    resource: src/Extensions/ExtensionBundle.php
    title: PhpFinder and ExtensionBundle
  - id: combine
    resource: src/Runtime/MicroCombiner.php
    title: MicroIni and MicroCombiner
  - id: bundle
    resource: src/Targets/MacAppBundle.php
    title: MacAppBundle and CodeSigner
  - id: cmd-test
    resource: tests/Console/BuildCommandTest.php
    title: end-to-end over fakes
---

# Steps

| # | Step | Class | Pinned by |
|---|---|---|---|
| 1 | Refuse unless `composer.json` requires `venusian/framework`, `computer` and `bootstrap/app.php` exist | `AppInspector`[^inspector] | `AppInspectorTest`, `BuildCommandTest` refusal |
| 2 | Manifest: `config/build.php` over defaults, `app.name`, sketches, `ext-*` from `composer.lock`; evaluated in a child PHP with `env()` reading `.env`; packaged `.env` = the app's `.env`, `build.env` over it, less `build.env_except` | `ManifestReader`[^reader] | `ManifestReaderTest` |
| 3 | Interview: name, bundle id, version, sketch (when several), signing; defaults stand without a terminal; several sketches and no terminal → refuse | `Interview`[^interview] | `BuildCommandTest` interview |
| 4 | Extension source: `build.php`, else the PHP running venusian; NTS → its `extension_dir`, ZTS → the NTS directory beside it (`-zts` suffix dropped); a configured ZTS binary refused by name | `PhpFinder`[^ext] | `PhpFinderTest` |
| 5 | Phar: stage without `vendor storage tests build .git .idea .vscode .zed bootstrap/cache node_modules .env* *.log`; `composer install --no-dev`; writable skeleton; `pack.php` with metadata `name bundle_id version sketch`; stub runs `rocket <sketch>`, or a script inside the phar named as the first argument (pool workers); symlinked path packages followed | `PharBuilder`[^phar] | `PharBuilderTest` |
| 6 | Runtime: latest release of `repository`, nested `bin/<os>/<arch>/php-<minor>.zip` → `micro.sfx`, cached under `~/.venusian/build/runtimes`, built-ins probed once | `RuntimeStore`[^runtime] | `RuntimeStoreTest` |
| 7 | `.so` per wanted extension the runtime lacks, plus kqueue and pcurl when present, from step 4's directory; a missing required one → named | `ExtensionBundle`[^ext] | `ExtensionBundleTest` |
| 8 | Executable = sfx + `\xfd\xf6\x69\xe6` + `pack('N', len)` + ini + phar; ini has `micro.php_binary=./<kebab>-bin` (PHP_BINARY for the pools), `extension_dir=lib` and one `extension=` per file | `MicroCombiner`[^combine] | `MicroCombinerTest` |
| 9 | `<Name>.app/Contents/{Info.plist, MacOS/<kebab>, MacOS/<kebab>-bin, MacOS/lib/*.so, Resources/AppIcon.icns}`; launcher `cd`s to its directory then `exec`s | `MacAppBundle`[^bundle] | `MacAppBundleTest` |
| 10 | `codesign --force --sign -` on the bundle (adhoc), or with an identity: the runtime image before the payload is appended, each `.so`, then the bundle under `--options runtime --entitlements …`; never `--deep` | `CodeSigner`[^bundle] | `MacAppBundleTest` signs |

Output: `<app>/build/<Name>.app`, `build/.gitignore` = `*`. Targets other than `macos-arm64`, or a non-Darwin host, refuse before anything runs.[^build][^cmd-test]

[^command]: BuildCommand
[^build]: Build::run()
[^inspector]: AppInspector
[^reader]: ManifestReader and read.php
[^interview]: Interview
[^phar]: PharBuilder and pack.php
[^runtime]: RuntimeStore
[^ext]: PhpFinder and ExtensionBundle
[^combine]: MicroIni and MicroCombiner
[^bundle]: MacAppBundle and CodeSigner
[^cmd-test]: end-to-end over fakes
