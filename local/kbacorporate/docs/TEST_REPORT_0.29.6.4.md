# TEST REPORT — 0.29.6.4

Static release checks performed:

- PHP syntax check for modified PHP files: PASS (where PHP CLI available).
- Plugin ZIP has one top-level directory: `kbacorporate/`.
- `version.php` build incremented to `2026090308`.
- No database schema changes.
- ZIP integrity check: PASS.
- SHA-256 generated for release artifact.

Manual Moodle UI regression is required after installation, especially for role/capability-specific menu visibility and responsive wrapping.
