# BMS24 — Documentation Packaging Standard

From build2026101693 every INSTALL package is self-contained for technical documentation.

## Folders
- `docs/` — architecture, technical standards, module notes and implementation documentation.
- `release/` — release notes, acceptance notes, changed-path manifests and the local release register.

## Rules
1. Runtime PHP/JS/CSS must not depend on markdown/txt documentation paths.
2. A release must update version.php, db/upgrade.php, release note and local release register.
3. Google Drive remains the management mirror: Master Context, Roadmap, Release Register and Master List must be updated after a release checkpoint.
4. Package-local documentation is the technical snapshot for the exact installed build; Google Drive is the cross-release management index.
5. Do not place INSTALL/SOURCE ZIP files inside the plugin `release/` folder to avoid recursive archives. Store ZIP artifacts outside the plugin package.

## Machine-enforced checkpoint (build2026101694+)

Before any ZIP is produced, run the plugin-local `cli/release_docs_checkpoint.php` prepare step and then `--check`. A missing release note, stale `CURRENT_RELEASE.json`, missing local register row, or stale checkpoint JSON is a packaging blocker.
