---
type: Concept
title: Measured facts
description: What was measured on 2026-10-08 on a Mac (arm64, macOS 15) about phpmicro, php-bin, PHPacker and signing. The design rests on these; re-measure before contradicting one.
resource: docs/superpowers/specs/2026-10-08-venusian-build-design.md
tags: [build, phpmicro, php-bin, phpacker, codesign, measured]
status: draft
generated: { by: "claude-fable-5-1", at: "2026-10-08T00:00:00Z" }
sources:
  - id: spec
    resource: docs/superpowers/specs/2026-10-08-venusian-build-design.md
    title: design spec, section "Measured facts"
---

Against `phpacker/php-bin` 0.4.0 (PHP 8.4.15 micro, NTS) and Homebrew-built extensions in `/opt/homebrew/lib/php/pecl/20240924`.[^spec]

1. A stock macOS `micro.sfx` loads shared extensions named in its embedded ini: kqueue, appkit, imgdec, opengl, metal all loaded in one process. An extension built against Homebrew PHP 8.4.25 NTS loads in micro 8.4.15 NTS; the API number (20240924) is what matters.
2. `dl()` does not exist in the micro SAPI.
3. PHPacker's `Combine::encodeINI` parses the ini into an associative array: repeated `extension=` keys collapse to the last. Hence `MicroCombiner` writes the block itself.
4. A relative `extension_dir` in the embedded ini resolves against the working directory, not the executable. Hence the launcher `cd`s.
5. The combined binary keeps the `adhoc, linker-signed` signature `micro.sfx` shipped with; the payload is outside the signed range and macOS runs it. The bundle is still signed as a whole.
6. php-bin Linux binaries are fully static musl and cannot `dlopen`. Linux needs a glibc runtime built with static-php-cli `SPC_TARGET`; later wave; hence `repository` is configurable.
7. The `zenusian` alias runs the installer under `zhp`, PHP 8.4 ZTS; ZTS extensions cannot load into the NTS runtime. Hence `PhpFinder`.
8. static-php-cli's registry plugin system exists only on its unreleased main branch (3.0); 2.8.5 is current. Nothing here depends on static-php-cli.

[^spec]: design spec, section "Measured facts"
