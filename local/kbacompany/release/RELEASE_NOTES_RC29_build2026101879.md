# BMS24 build2026101879 — Verified Git artifact before publishing

- Git bundle importer validates that the exact commit can reproduce the reviewed INSTALL ZIP.
- Publishing an import branch requires both a reviewed ZIP and its approved SHA-256; default dry run never pushes.
- A mismatch in any Moodle installable file blocks branch publication before git push.
- GitHub remote remains at earlier code until independently approved transfer; production remains unchanged.
- Version-only Moodle upgrade checkpoint 2026101879; no schema or business-data migrations.
