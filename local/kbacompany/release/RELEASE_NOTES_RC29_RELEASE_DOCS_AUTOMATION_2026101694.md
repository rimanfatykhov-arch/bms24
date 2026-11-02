# BMS24 1.1.0-rc29 — build2026101694

## Release documentation checkpoint automation

This documentation-only release makes the plugin-local documentation snapshot enforceable by build tooling.

### Added

- `cli/release_docs_checkpoint.php` — standalone PHP 7.4-compatible pre-package hook.
- Prepare mode updates `release/CURRENT_RELEASE.json`, creates a build-specific `DOCUMENTATION_CHECKPOINT_<build>.json`, and upserts `release/RELEASE_REGISTER_LOCAL.md`.
- `--check` mode exits non-zero when the package version and documentation checkpoint diverge.
- Build/QA automation can therefore block packaging before a ZIP is produced when release documentation is incomplete.

### Guard contract

Before packaging:

```bash
php local/kbacompany/cli/release_docs_checkpoint.php \
  --summary="Short release summary" --live="NOT RUN"
php local/kbacompany/cli/release_docs_checkpoint.php --check
```

A release note containing the current build number must exist before the checkpoint can be prepared.

### Not changed

- no runtime BMS24 behavior;
- no DB schema;
- no ACL/RBAC;
- no Battle Plan/Task lifecycle;
- no company-isolation semantics.

### Acceptance

Static/package checks only. Full Moodle regression: NOT RUN.
