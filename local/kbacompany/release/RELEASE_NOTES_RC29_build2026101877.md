# BMS24 local_kbacompany build2026101877 — GitHub/VDS import readiness

- Cumulative maintenance release `1.1.0-rc29`, plugin build `2026101877`.
- Build-level Moodle upgrade checkpoint only. **No DDL, no business-record migration, no changes to permissions**.
- Application business logic is identical to build2026101876.
- Development source repository adds `tools/github_import.py` and isolated Git integration tests. These Python tools are **excluded from the Moodle INSTALL ZIP**.
- GitHub branch `import/build2026101876` is a documentation placeholder; build1877 is **not yet pushed** to GitHub. No CI, server deployment or RC30 acceptance was performed as part of this release.
- Full source and Git bundle are separate from installable Moodle plugin.

## Installation

Use the full `INSTALL_local_kbacompany_1.1.0-rc29_build2026101877.zip` for a clean, cumulative plugin upgrade, with backup of Moodle database and installed plugin first; run Moodle `/admin/index.php` upgrade and clear caches. Staging acceptance is required. No need to install Python or Git on the PHP host.
