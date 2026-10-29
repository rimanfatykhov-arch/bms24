# BMS24 release documentation automation

Starting with build `2026101694`, plugin packaging has a machine-checkable documentation gate.

## Canonical pre-package sequence

1. Update `version.php` and add the matching upgrade savepoint.
2. Add a release note in `release/` whose filename contains the current build.
3. Run:

   ```bash
   php local/kbacompany/cli/release_docs_checkpoint.php \
     --summary="Short release summary" --live="NOT RUN"
   ```

4. Block packaging unless this passes:

   ```bash
   php local/kbacompany/cli/release_docs_checkpoint.php --check
   ```

5. Only after the package exists, synchronize Google MASTER LIST / BMS-MASTER / ROADMAP / RELEASE REGISTER and perform readback.

## Files maintained by the hook

- `release/CURRENT_RELEASE.json`
- `release/DOCUMENTATION_CHECKPOINT_<build>.json`
- `release/RELEASE_REGISTER_LOCAL.md`

## Why the Google update remains outside the plugin build hook

The plugin package must stay portable and must not contain Google credentials or depend on an external account to install. Google Drive is the management mirror; `docs/` + `release/` are the self-contained technical snapshot.

## Hard rule

A future build is documentation-ready only when `--check` exits `0`. Build pipelines should treat exit `2` as a hard packaging blocker.
