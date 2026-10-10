---
type: Concept
title: Build pipeline
description: The steps of `venusian build` in order, the class behind each, and what each target does with the phar.
resource: src/Build.php
tags: [build, pipeline, phar, sapi, codesign, notarization, macos, linux, deb, docker]
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
    resource: src/Runtime/MacRuntime.php
    title: MacRuntime and MacLibraries
  - id: ext
    resource: src/Extensions/ExtensionSet.php
    title: ExtensionSet
  - id: macenv
    resource: build-env/macos/recipe.sh
    title: build-env/macos libs.sh and recipe.sh
  - id: dmg
    resource: src/Targets/MacDiskImage.php
    title: MacDiskImage
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
| Available | on an Apple Silicon Mac with xcrun, cmake, autoconf, pkg-config | a docker context of the target's CPU: the one `~/.venusian/build/config.json` names, else the current context[^hosts] |
| PHP | php-src at the newest release of the PHP running the build's minor + Venusian SAPI, thread safety of that PHP unless `build.json` `zts`, extensions compiled in, natively at macOS 14 against `~/.venusian/build/macos/prefix-14.0-<hash>`[^runtime][^macenv] | php-src at the newest release of the PHP running the build's minor + Venusian SAPI v0.10.2, same thread safety rule, extensions compiled in, in `ghcr.io/venusianphp/build-env:ubuntu24.04-<hash>` (GLFW 3.4, SDL3 and libjpeg-turbo static; Vulkan headers 1.4.309)[^deb][^recipe] |
| Platform extensions | kqueue, pcurl, rasterize always (base set); gtk and qt left out | epoll, pcurl, rasterize always (base set) |
| Output | `build/<Name>.app` and `build/<kebab>-<version>-macos-arm64.dmg`, notarized with a Developer ID and a profile[^bundle][^dmg] | `build/<kebab>_<version>_<arch>.deb` |
| Interview asks signing | once per machine, saved to the per-user config | no |

# Steps

| # | Step | Class | Pinned by |
|---|---|---|---|
| 1 | Refuse unless `composer.json` requires `venusian/framework`, `computer` and `bootstrap/app.php` exist | `AppInspector`[^inspector] | `AppInspectorTest`, `BuildCommandTest` refusal |
| 2 | `build.json` over defaults; unknown key, non-reverse-DNS id, unknown target, version not starting with a digit, invalid JSON, `sign`/`php`/`repository` (moved), build < 1, category or permission outside the lists → refused by name | `BuildJson`[^buildjson] | `BuildJsonTest` |
| 3 | Manifest: `app.name`, `app.id` (missing → told to add it; differs from `build.json` `id` → the pissy error), sketches, `ext-*` from `composer.lock` (packages' requirements and the app's own under `platform`), the default database connection's driver and file (`config/database.php` evaluated with `build.json` `env` over `.env`, less `env_except`), windowed = a `jovian/*` package in the lock; evaluated in a child PHP with `env()` reading `.env`; packaged `.env` = the app's `.env`, `build.env` over it, less `build.env_except`, `APP_ID` always | `ManifestReader`[^reader] | `ManifestReaderTest` |
| 4 | Interview: name, version, sketch (when several), author and summary while empty, then, once per machine with a terminal and a signing target, how macOS builds sign (Developer ID from the keychain, notarytool profile) into ~/.venusian/build/config.json; defaults stand without a terminal; the id never asked; changed answers plus the id written back to `build.json` | `Interview`[^interview], `BuildCommand`[^command] | `InterviewTest`, `BuildCommandTest` |
| 5 | Phar, once: a default connection other than sqlite → refused (sqlite only for now); stage = what git would publish (tracked plus untracked-not-ignored, less `export-ignore`; a directory's rule covers its subtree; the whole tree when not a repository) without `vendor storage tests build .git .idea .vscode .zed bootstrap/cache node_modules .env* *.log`; relative path repositories made absolute; `composer install --no-dev`; each path-repository package replaced by what its own tree publishes (links unlinked, never followed); `dump-autoload --classmap-authoritative`; writable skeleton; `.env`; a sqlite app whose file is `database/database.sqlite` gets it fresh and its packaged `.env` loses `DB_DATABASE`, migrated by `seed.php` with a temporary `bootstrap/cache` (only a last-line count proves the run); `.venusian-boot-check.php` at the root; the scan; metadata `name id version sketch`; stub runs `rocket <sketch>`, or a script inside the phar named as the first argument (pool workers) | `PharBuilder`[^phar], `PublishedFiles`[^published] | `PharBuilderTest`, `PublishedFilesTest`, `BuildTest` |
| 5a | Scan: nikic/php-parser over the stage against `resources/extension-symbols.json` (functions, classes, constants per extension; `bin/extension-symbols` regenerates it from running PHPs); app code adds the extension with `Adding <ext>: <file> uses <symbol>`, vendor code is reported as `Vendor code also calls, not compiled in: …`; a sqlite default connection adds pdo_sqlite; always-on and base extensions are never named | `ExtensionScan`, `SymbolMap`[^scan], `Build`[^build] | `ExtensionScanTest`, `SymbolMapTest`, `ExtensionSetTest`, `BuildTest` |
| 6 | macOS: `ExtensionSet` for darwin with the SDK path (iconv, bz2 from the SDK, ldap from the static OpenLDAP; gtk, qt left out; gettext, gmp, intl, pdo_pgsql, pgsql, sodium, tidy, zip refused); `libs.sh` once per pin set (`.complete` last); `recipe.sh` natively with the shell's compiler variables unset, refusing links outside `/System/Library/`, `/usr/lib/`, `@rpath/`, an rpath other than `@executable_path/../Frameworks`, objects built for a newer macOS and a minos other than 14.0; binary per app and set hash (SDK version included), renamed into place; `.app` with `Frameworks` for `@rpath` libraries plus MoltenVK and its ICD manifest; identity resolved to its certificate's SHA-1 before the compile; `codesign` each dylib then the bundle, hardened, then `--verify --strict --deep`; boot check: the signed binary runs `phar://<realpath>/.venusian-boot-check.php` with HOME in a temp directory (boot, build the phar's sketch through the kernel's registry, open the default connection), any failure stops the build with what it said; `.dmg`; with an identity: sign the `.dmg`, `notarytool submit --wait`, staple, `spctl --assess` | `MacTarget`[^target], `ExtensionSet`[^ext], `MacLibraries`/`MacRuntime`[^runtime], `MacAppBundle`/`CodeSigner`[^bundle], `BootCheck`[^boot], `MacDiskImage`[^dmg] | `ExtensionSetTest`, `MacLibrariesTest`, `MacRuntimeTest`, `MacScriptsTest`, `MacAppBundleTest`, `MacDiskImageTest`, `MacTargetTest`, `BuildCommandTest` |
| 7 | Linux, resolve: base ctype, filter, mbstring, openssl, pdo, epoll, pcurl, rasterize, then the app's, then `PHP_ADD_EXTENSION_DEP` needs (pcurl → curl, epoll → sockets, dom → libxml…, redis → session), in that queue order; a `vendor/package` name first resolves to that php-ext package (newest stable tag) and stands for the extension name it declares; core names → `ExtensionSet::CORE` flags; other plain names → `php-io-extensions/<name>` on the 0.10 line: the `0.10.x-dev` entry of the `~dev` p2 index, else the newest `v?0.10.N` tag (minified entries expanded); neither → refused with Packagist's php-ext search hits; `php-ext` `extension-name`, `build-path`, the `enable-<name>`/`with-<name>` configure option (else the first), `support-zts`/`support-nts` (a mismatch with the build's thread safety → refused by name), `extra.venusian.system.apt` build/depends/recommends; `os-families` without linux or `os-families-exclude` with it → left out and reported; set hash = sha1 of image, PHP version, SAPI tag, zts, flags, each extension's version and commit | `ExtensionSet`[^ext], `DebTarget`[^deb], `Sources`[^sources] | `ExtensionSetTest`, `DebTargetTest`, `SourcesTest` |
| 8 | Linux, host: image present, else pulled, else built from `build-env/` sent as a tar on stdin; volume `venusian-build-<kebab>` cleared of `in out pkg`; one tar of `recipe.sh`, `package.sh`, `apt-build.sh`, `in/` (sources, `set.hash`, `php.version`, `configure.args`, `extensions.list`, `apt.build`, `deb/` metadata with `depends`, phar) pushed into it | `DebTarget`[^deb], `Docker`[^hosts] | `DebTargetTest`, `DockerTest` |
| 9 | Linux, compile: `recipe.sh` reuses `build/<hash>` when its hash matches, else runs `apt-build.sh` (installs the `in/apt.build` packages `dpkg -s` does not find), then replaces the volume's tree: php-src, SAPI into `sapi/venusian`, each extension's build path into `ext/<name>`, `buildconf --force`, `configure`, `make -j`; failure tails on stderr; boot check in the container: binary and phar copied to `/tmp/boot/bin/venusian{,.phar}` (the SAPI runs `<binary>.phar` beside it), HOME and `XDG_DATA_HOME` under `/tmp/boot`, no display needed | `recipe.sh`[^recipe], `BootCheck`[^boot] | `BuildEnvTest` (parse), `DebTargetTest`; the proof run |
| 10 | Linux, package: `/usr/lib/<kebab>/{<kebab>,<kebab>.phar}`, `/usr/bin/<kebab>` symlink, copyright; windowed: `<id>.desktop`, hicolor `<size>/apps/<id>.png`, `<id>.metainfo.xml`; `apt-build.sh` again (its own container); Depends from `dpkg-shlibdeps`, each `…t64` entry with its plain name as alternative, plus the `in/deb/depends` names it lacks, Recommends from the extensions; `dpkg-deb --root-owner-group`; fetched into `build/` | `package.sh`[^recipe], `DebTarget`[^deb] | `DebTargetTest` control, desktop, metainfo |

Icons for a `.deb` are square PNGs of at least 16 px, checked before anything reaches the host; `package.sh` renders each hicolor size up to 512 the source covers. The image tag carries the Dockerfile's sha1 (12 hex), so a changed Dockerfile builds a new image on every host and joins the set hash. PHP's own extensions outside `CORE` that the image cannot build (`UNCOMPILED`, opcache among them: PHP 8.4 builds it only as a zend_extension) stop the build by name instead of going to Packagist. A `.deb` needs `author` as its maintainer.[^deb] Output: `<app>/build/`, `build/.gitignore` = `*`.[^build][^cmd-test]

[^command]: BuildCommand
[^build]: Build::run()
[^inspector]: AppInspector
[^buildjson]: BuildJson and schema/build.schema.json
[^reader]: ManifestReader and read.php
[^interview]: Interview
[^phar]: PharBuilder and pack.php
[^target]: Target, MacTarget, DebTarget
[^runtime]: MacRuntime and MacLibraries
[^ext]: ExtensionSet
[^macenv]: build-env/macos libs.sh and recipe.sh
[^dmg]: MacDiskImage
[^published]: PublishedFiles
[^scan]: ExtensionScan and SymbolMap
[^boot]: BootCheck and boot-check.php
[^bundle]: MacAppBundle and CodeSigner
[^sources]: Sources
[^hosts]: Docker and UserConfig
[^deb]: DebTarget
[^recipe]: recipe.sh and package.sh
[^cmd-test]: end-to-end over fakes
