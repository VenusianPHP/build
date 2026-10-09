---
type: Concept
title: Build pipeline
description: The steps of `venusian build` in order, the class behind each, and what each target does with the phar.
resource: src/Build.php
tags: [build, pipeline, phar, phpmicro, codesign, linux, deb, docker]
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
  - id: buildjson
    resource: src/App/BuildJson.php
    title: BuildJson and schema/build.schema.json
  - id: reader
    resource: src/App/ManifestReader.php
    title: ManifestReader and read.php
  - id: interview
    resource: src/Console/Interview.php
    title: Interview
  - id: phar
    resource: src/Phar/PharBuilder.php
    title: PharBuilder and pack.php
  - id: target
    resource: src/Targets/Target.php
    title: Target, MacTarget, DebTarget
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
  - id: sources
    resource: src/Sources/Sources.php
    title: Sources
  - id: hosts
    resource: src/Hosts/Docker.php
    title: Docker and UserConfig
  - id: deb
    resource: src/Targets/DebTarget.php
    title: DebTarget
  - id: recipe
    resource: build-env/recipe.sh
    title: recipe.sh and package.sh
  - id: cmd-test
    resource: tests/Console/BuildCommandTest.php
    title: end-to-end over fakes
---

# Targets

`build.targets` names what to build; empty means this machine, which `Build::hostName()` maps from the OS family and CPU to `macos-arm64`, `linux-arm64` or `linux-x86_64`. `Build` packs the phar once and hands it to every named target whose `available()` holds; the rest are reported with `unavailableReason()`; none buildable throws, listing each with its reason; an unknown name throws, naming the known ones.[^build][^target]

| | `MacTarget` | `DebTarget` |
|---|---|---|
| Available | on a Mac | a docker context of the target's CPU: the one `~/.venusian/build/config.json` names, else the current context[^hosts] |
| PHP | php-bin NTS `micro.sfx`, `.so` files from `PhpFinder::nts()`[^runtime][^ext] | php-src 8.4.26 + Venusian SAPI v0.10.1, extensions compiled in, in `ghcr.io/venusianphp/build-env:ubuntu24.04`[^deb][^recipe] |
| Platform extensions | kqueue, pcurl when present | epoll, pcurl always (base set) |
| Output | signed `build/<Name>.app` | `build/<kebab>_<version>_<arch>.deb` |
| Interview asks signing | yes | no |

# Steps

| # | Step | Class | Pinned by |
|---|---|---|---|
| 1 | Refuse unless `composer.json` requires `venusian/framework`, `computer` and `bootstrap/app.php` exist | `AppInspector`[^inspector] | `AppInspectorTest`, `BuildCommandTest` refusal |
| 2 | `build.json` over defaults; unknown key, non-reverse-DNS id, unknown target, version not starting with a digit, invalid JSON → refused by name | `BuildJson`[^buildjson] | `BuildJsonTest` |
| 3 | Manifest: `app.name`, `app.id` (missing → told to add it; differs from `build.json` `id` → the pissy error), sketches, `ext-*` from `composer.lock`, windowed = a `jovian/*` package in the lock; evaluated in a child PHP with `env()` reading `.env`; packaged `.env` = the app's `.env`, `build.env` over it, less `build.env_except`, `APP_ID` always | `ManifestReader`[^reader] | `ManifestReaderTest` |
| 4 | Interview: name, version, sketch (when several), author and summary while empty, signing when a buildable target signs; defaults stand without a terminal; the id never asked; changed answers plus the id written back to `build.json` | `Interview`[^interview], `BuildCommand`[^command] | `InterviewTest`, `BuildCommandTest` |
| 5 | Phar, once: stage without `vendor storage tests build .git .idea .vscode .zed bootstrap/cache node_modules .env* *.log`; `composer install --no-dev`; writable skeleton; metadata `name id version sketch`; stub runs `rocket <sketch>`, or a script inside the phar named as the first argument (pool workers) | `PharBuilder`[^phar] | `PharBuilderTest`, `BuildTest` |
| 6 | macOS: runtime from the latest `repository` release cached under `~/.venusian/build/runtimes`; `.so` per wanted extension the runtime lacks plus kqueue/pcurl; sfx + `\xfd\xf6\x69\xe6` + `pack('N', len)` + ini + phar; `<Name>.app`; `codesign` ad hoc, or identity on the runtime image, each `.so`, then the bundle under `--options runtime`, never `--deep` | `MacTarget`[^target], `RuntimeStore`[^runtime], `ExtensionBundle`[^ext], `MicroCombiner`[^combine], `MacAppBundle`/`CodeSigner`[^bundle] | `RuntimeStoreTest`, `ExtensionBundleTest`, `MicroCombinerTest`, `MacAppBundleTest`, `BuildCommandTest` |
| 7 | Linux, resolve: base ctype, filter, mbstring, openssl, pdo, epoll, pcurl, then the app's, then `PHP_ADD_EXTENSION_DEP` needs (pcurl → curl, epoll → sockets, dom → libxml…), in that queue order; core names → `DebTarget::CORE` flags; others → latest tagged `php-io-extensions/<name>` on Packagist (p2 first entry), `php-ext` `build-path` and first configure option; `os-families` without linux or `os-families-exclude` with it → left out and reported; set hash = sha1 of PHP version, SAPI tag, zts, flags, extension versions | `DebTarget`[^deb], `Sources`[^sources] | `DebTargetTest`, `SourcesTest` |
| 8 | Linux, host: image present, else pulled, else built from `build-env/` sent as a tar on stdin; volume `venusian-build-<kebab>` cleared of `in out pkg`; one tar of `recipe.sh`, `package.sh`, `in/` (sources, `set.hash`, `php.version`, `configure.args`, `extensions.list`, `deb/` metadata, phar) pushed into it | `DebTarget`[^deb], `Docker`[^hosts] | `DebTargetTest`, `DockerTest` |
| 9 | Linux, compile: `recipe.sh` reuses `build/<hash>` when its hash matches, else replaces the volume's tree: php-src, SAPI into `sapi/venusian`, each extension's build path into `ext/<name>`, `buildconf --force`, `configure`, `make -j`; failure tails on stderr | `recipe.sh`[^recipe] | `BuildEnvTest` (parse); the proof run |
| 10 | Linux, package: `/usr/lib/<kebab>/{<kebab>,<kebab>.phar}`, `/usr/bin/<kebab>` symlink, copyright; windowed: `<id>.desktop`, hicolor `<size>/apps/<id>.png`, `<id>.metainfo.xml`; Depends from `dpkg-shlibdeps`; `dpkg-deb --root-owner-group`; fetched into `build/` | `package.sh`[^recipe], `DebTarget`[^deb] | `DebTargetTest` control, desktop, metainfo |

Icons for a `.deb` are square PNGs of at least 16 px, checked before anything reaches the host; `package.sh` renders each hicolor size up to 512 the source covers. The image tag carries the Dockerfile's sha1 (12 hex), so a changed Dockerfile builds a new image on every host and joins the set hash. PHP's own extensions outside `CORE` that the image cannot build (`UNCOMPILED`, opcache among them: PHP 8.4 builds it only as a zend_extension) stop the build by name instead of going to Packagist. A `.deb` needs `author` as its maintainer.[^deb] Output: `<app>/build/`, `build/.gitignore` = `*`.[^build][^cmd-test]

[^command]: BuildCommand
[^build]: Build::run()
[^inspector]: AppInspector
[^buildjson]: BuildJson and schema/build.schema.json
[^reader]: ManifestReader and read.php
[^interview]: Interview
[^phar]: PharBuilder and pack.php
[^target]: Target, MacTarget, DebTarget
[^runtime]: RuntimeStore
[^ext]: PhpFinder and ExtensionBundle
[^combine]: MicroIni and MicroCombiner
[^bundle]: MacAppBundle and CodeSigner
[^sources]: Sources
[^hosts]: Docker and UserConfig
[^deb]: DebTarget
[^recipe]: recipe.sh and package.sh
[^cmd-test]: end-to-end over fakes
