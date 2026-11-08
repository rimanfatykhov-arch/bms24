# BMS24 1.1.0-rc29 — build2026101695

## One-command release runner

Baseline: build2026101694.

This release adds `cli/build_release.py`, a portable pre-package runner intended for the local source tree. It:

- reads `version.php` and the current release/build;
- requires the current build Release Note;
- runs `cli/release_docs_checkpoint.php` in prepare and check modes;
- validates the current upgrade checkpoint and `db/install.xml`;
- checks RU/EN language-key parity and duplicate keys;
- PHP-lints every plugin PHP file;
- creates an INSTALL ZIP with the canonical top-level `kbacompany/` folder using Python's standard library;
- calculates SHA256 and writes `SHA256SUMS.txt`;
- writes `BUILD_RESULT.json` with READY/HOLD status and artifact metadata.

The runner intentionally does not embed Google credentials and does not push documentation to Google Drive. Google synchronization + readback remains the external post-package management step.

No Moodle runtime, DB schema, ACL/RBAC, lifecycle, privacy or company-isolation semantics are changed.

Live Moodle full regression: NOT RUN.
