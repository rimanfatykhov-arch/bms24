# BMS24 1.1.0-rc29 — build2026101693

Documentation Recovery + Self-contained Release Docs.

- Restores the release/documentation checkpoint after build2026101642.
- Introduces plugin-local `docs/` and `release/` folders so every INSTALL package carries its own technical documentation snapshot.
- Moves historical technical markdown/text files from the plugin root into the appropriate folder while keeping runtime code unchanged.
- Adds `docs/MASTER_INDEX.md`, `docs/GOOGLE_DRIVE_INDEX.md`, `docs/DOCUMENTATION_PACKAGING_STANDARD.md`, `release/RELEASE_REGISTER_LOCAL.md` and `release/CURRENT_RELEASE.json`.
- Google Drive remains the management/document mirror; the plugin package remains the technical self-contained snapshot.
- No DB schema, ACL/RBAC, lifecycle, privacy or company-isolation changes.
- Live Moodle regression for runtime behavior is not required by this documentation-only checkpoint; installation acceptance remains recommended.
