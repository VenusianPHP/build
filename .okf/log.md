# Directory Update Log

## 2026-10-08

* **Creation**: first bundle for `venusian/build` 0.10.0 — [Package](orientation/package.md), [Build pipeline](core/build-pipeline.md), [Measured facts](core/measured-facts.md). All `status: draft`. The framework carries no build code; `venusian build` packs the app from its own directory.

* **Packaged .env**: the app's `.env` ships by default with `build.env` over it, less `build.env_except`; an app without `config/build.php` no longer starts with no toolkit.
